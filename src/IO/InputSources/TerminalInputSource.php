<?php

namespace Ichiloto\Engine\IO\InputSources;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use InvalidArgumentException;
use RuntimeException;

/** Terminal parsing only. The caller still owns the stream and terminal modes. */
final class TerminalInputSource implements InputSourceInterface
{
  private string $pendingInput = '';
  /** @var resource */
  private $stream;

  /** @param resource|null $stream Defaults to STDIN; injected streams remain caller-owned. */
  public function __construct($stream = null)
  {
    $stream ??= STDIN;
    if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
      throw new InvalidArgumentException('Terminal input requires a readable stream.');
    }
    $this->stream = $stream;
  }

  public function poll(): ?KeyCode
  {
    LatencyTrace::beginPoll();
    $started = LatencyTrace::now();
    $key = $this->nonBlocking(fn() => self::normalize($this->readInputSequence()));
    if ($key !== null) { LatencyTrace::returned($key->value, self::class, $started); }
    return $key;
  }

  public function reset(bool $drainBufferedInput = false): void
  {
    $this->pendingInput = '';
    if ($drainBufferedInput) {
      $this->nonBlocking(function (): void {
        // A continuously writing peer must not trap a scene transition in a drain loop.
        for ($bytes = 0; $bytes < 65536; $bytes += strlen($chunk)) {
          $chunk = fread($this->stream, 1024);
          if ($chunk === false) {
            throw new RuntimeException('Failed to drain terminal input.');
          }
          if ($chunk === '') {
            return;
          }
        }
        if (fread($this->stream, 1) !== '') {
          throw new RuntimeException('Terminal input drain exceeded its 64 KiB budget.');
        }
      });
    }
  }

  private static function normalize(string $sequence): ?KeyCode
  {
    return match ($sequence) {
      "\033[A" => KeyCode::UP,
      "\033[B" => KeyCode::DOWN,
      "\033[C" => KeyCode::RIGHT,
      "\033[D" => KeyCode::LEFT,
      "\033[Z" => KeyCode::SHIFT_TAB,
      "\r", "\n" => KeyCode::ENTER,
      ' ' => KeyCode::SPACE,
      "\010", "\177" => KeyCode::BACKSPACE,
      "\t" => KeyCode::TAB,
      "\033" => KeyCode::ESCAPE,
      "\033[1~", "\033[H", "\033OH", "\033[7~" => KeyCode::HOME,
      "\033[2~" => KeyCode::INSERT,
      "\033[3~" => KeyCode::DELETE,
      "\033[8~", "\033[F", "\033OF", "\033[4~" => KeyCode::END,
      "\033[5~" => KeyCode::PAGE_UP,
      "\033[6~" => KeyCode::PAGE_DOWN,
      "\033[10~" => KeyCode::F0,
      "\033[11~" => KeyCode::F1,
      "\033[12~" => KeyCode::F2,
      "\033[13~" => KeyCode::F3,
      "\033[14~" => KeyCode::F4,
      "\033[15~" => KeyCode::F5,
      "\033[17~" => KeyCode::F6,
      "\033[18~" => KeyCode::F7,
      "\033[19~" => KeyCode::F8,
      "\033[20~" => KeyCode::F9,
      "\033[21~" => KeyCode::F10,
      "\033[23~" => KeyCode::F11,
      "\033[24~" => KeyCode::F12,
      default => KeyCode::tryFrom($sequence),
    };
  }

  private function readInputSequence(): string
  {
    $input = $this->pendingInput;
    $this->pendingInput = '';
    if ($input === '') {
      $input = fread($this->stream, 32);
    }
    if ($input === false) {
      throw new RuntimeException('Failed to read terminal input.');
    }
    if ($input === '') {
      return '';
    }
    if (! str_starts_with($input, "\033")) {
      $this->pendingInput = substr($input, 1);
      return $input[0];
    }

    $sequence = $input;
    $emptyReads = 0;
    // Keep the established short escape-completion window and splitting rules.
    for ($attempt = 0; $attempt < 4; $attempt++) {
      if (self::isCompleteEscapeSequence($sequence)) {
        break;
      }
      usleep(1000);
      $chunk = fread($this->stream, 32);
      if ($chunk === false || $chunk === '') {
        if (++$emptyReads >= 2) {
          break;
        }
        continue;
      }
      $emptyReads = 0;
      $sequence .= $chunk;
    }
    [$first, $this->pendingInput] = self::splitInputSequence($sequence);
    return $first;
  }

  /** @return array{string, string} */
  private static function splitInputSequence(string $input): array
  {
    if (preg_match('/^\033(\[[0-9;?<]*[~A-Za-z]|\[<\d+;\d+;\d+[mM]|O[A-Za-z])/', $input, $matches) === 1) {
      return [$matches[0], substr($input, strlen($matches[0]))];
    }
    return [$input[0], substr($input, 1)];
  }

  private static function isCompleteEscapeSequence(string $sequence): bool
  {
    return $sequence !== "\033"
      && preg_match('/^\033(\[[0-9;?<]*[~A-Za-z]|\[<\d+;\d+;\d+[mM]|O[A-Za-z])$/', $sequence) === 1;
  }

  /** Temporary nonblocking reads also make standalone/injected-stream use safe. */
  private function nonBlocking(callable $operation): mixed
  {
    $wasBlocking = stream_get_meta_data($this->stream)['blocked'] ?? false;
    if (! stream_set_blocking($this->stream, false)) {
      throw new RuntimeException('Failed to enable nonblocking terminal input.');
    }
    try {
      return $operation();
    } finally {
      if ($wasBlocking) {
        stream_set_blocking($this->stream, true);
      }
    }
  }
}
