<?php

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;

function terminalInputStream(string $bytes)
{
  $stream = fopen('php://temp', 'r+');
  fwrite($stream, $bytes);
  rewind($stream);
  return $stream;
}

it('preserves supported terminal key mappings', function ($bytes, $key) {
  $stream = terminalInputStream($bytes);
  try {
    $source = new TerminalInputSource($stream);
    expect($source->poll())->toBe($key)->and($source->poll())->toBeNull();
  } finally {
    fclose($stream);
  }
})->with([
  ['a', KeyCode::a], ['w', KeyCode::w], ['W', KeyCode::W],
  ["\033[A", KeyCode::UP], ["\033[B", KeyCode::DOWN],
  ["\033[C", KeyCode::RIGHT], ["\033[D", KeyCode::LEFT],
  ["\r", KeyCode::ENTER], ["\n", KeyCode::ENTER], [' ', KeyCode::SPACE],
  ["\033", KeyCode::ESCAPE], ["\t", KeyCode::TAB], ["\033[Z", KeyCode::SHIFT_TAB],
  ["\010", KeyCode::BACKSPACE], ["\177", KeyCode::BACKSPACE],
  ["\033[1~", KeyCode::HOME], ["\033[H", KeyCode::HOME], ["\033OH", KeyCode::HOME], ["\033[7~", KeyCode::HOME],
  ["\033[2~", KeyCode::INSERT], ["\033[3~", KeyCode::DELETE],
  ["\033[8~", KeyCode::END], ["\033[F", KeyCode::END], ["\033OF", KeyCode::END], ["\033[4~", KeyCode::END],
  ["\033[5~", KeyCode::PAGE_UP], ["\033[6~", KeyCode::PAGE_DOWN],
  ["\033[10~", KeyCode::F0], ["\033[11~", KeyCode::F1], ["\033[12~", KeyCode::F2],
  ["\033[13~", KeyCode::F3], ["\033[14~", KeyCode::F4], ["\033[15~", KeyCode::F5],
  ["\033[17~", KeyCode::F6], ["\033[18~", KeyCode::F7], ["\033[19~", KeyCode::F8],
  ["\033[20~", KeyCode::F9], ["\033[21~", KeyCode::F10], ["\033[23~", KeyCode::F11], ["\033[24~", KeyCode::F12],
]);

it('buffers concatenated terminal sequences in order and consumes one per poll', function () {
  $stream = terminalInputStream("\033[A\033[AwW\r");
  try {
    $source = new TerminalInputSource($stream);
    expect([$source->poll(), $source->poll(), $source->poll(), $source->poll(), $source->poll(), $source->poll()])
      ->toBe([KeyCode::UP, KeyCode::UP, KeyCode::w, KeyCode::W, KeyCode::ENTER, null]);
  } finally {
    fclose($stream);
  }
});

it('clears cached terminal bytes without draining unread bytes unless requested', function ($drain) {
  $stream = terminalInputStream(str_repeat('a', 32) . 'W');
  try {
    $source = new TerminalInputSource($stream);
    expect($source->poll())->toBe(KeyCode::a);
    $source->reset($drain);
    expect($source->poll())->toBe($drain ? null : KeyCode::W);
  } finally {
    fclose($stream);
  }
})->with([false, true]);

it('returns null without blocking an empty pipe and restores its blocking mode', function () {
  [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
  try {
    $source = new TerminalInputSource($read);
    $started = hrtime(true);
    expect($source->poll())->toBeNull();
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.1);
    expect(stream_get_meta_data($read)['blocked'])->toBeTrue();
    unset($source);
    expect(is_resource($read))->toBeTrue();
  } finally {
    fclose($read);
    fclose($write);
  }
});

/** A stream that deterministically returns one chunk per read, with no process/timing dependency. */
final class ChunkedTerminalTestStream
{
  public $context;
  public static array $chunks = [];
  public function stream_open($path, $mode, $options, &$openedPath): bool { return true; }
  public function stream_read($count): string { return array_shift(self::$chunks) ?? ''; }
  public function stream_eof(): bool { return self::$chunks === []; }
  public function stream_stat(): array { return []; }
  public function stream_set_option($option, $arg1, $arg2): bool { return true; }
}

it('completes an escape sequence split across terminal reads', function () {
  stream_wrapper_register('ichilototerminaltest', ChunkedTerminalTestStream::class);
  ChunkedTerminalTestStream::$chunks = ["\033", '[', 'A'];
  $stream = fopen('ichilototerminaltest://input', 'r');
  try {
    expect(new TerminalInputSource($stream)->poll())->toBe(KeyCode::UP);
  } finally {
    fclose($stream);
    stream_wrapper_unregister('ichilototerminaltest');
  }
});

it('bounds draining a busy input stream and accepts an exactly full drain budget', function ($overflow) {
  $stream = terminalInputStream(str_repeat('a', 65536 + ($overflow ? 1 : 0)));
  try {
    $source = new TerminalInputSource($stream);
    if ($overflow) {
      expect(fn() => $source->reset(true))->toThrow(RuntimeException::class, 'budget');
    } else {
      $source->reset(true);
      expect($source->poll())->toBeNull();
    }
  } finally {
    fclose($stream);
  }
})->with([false, true]);
