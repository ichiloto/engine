<?php

namespace Ichiloto\Engine\Rendering\Transport\Internal;

use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;

/** Bounded ordered bytes; consuming a short write never drops the remainder. @internal */
final class RendererWriteBuffer
{
  private string $bytes = '';
  private int $offset = 0;

  public function __construct(private readonly int $capacity)
  {
  }

  public function append(string $line): void
  {
    if ($this->pendingBytes() + strlen($line) > $this->capacity) {
      throw new RendererTransportException('Renderer outbound buffer is full; message was not queued.');
    }
    $this->bytes = substr($this->bytes, $this->offset) . $line;
    $this->offset = 0;
  }

  /** @param callable(string): (int|false) $write */
  public function flush(callable $write, int $budget): void
  {
    while ($this->pendingBytes() > 0 && $budget > 0) {
      $chunk = substr($this->bytes, $this->offset, min(8192, $budget));
      $written = $write($chunk);
      if ($written === false || $written < 0 || $written > strlen($chunk)) {
        throw new RendererTransportException('Renderer stdin write failed with ' . $this->pendingBytes() . ' bytes pending.');
      }
      if ($written === 0) {
        break;
      }
      $this->offset += $written;
      $budget -= $written;
    }
    if ($this->offset === strlen($this->bytes)) {
      $this->bytes = '';
      $this->offset = 0;
    }
  }

  public function pendingBytes(): int
  {
    return strlen($this->bytes) - $this->offset;
  }
}
