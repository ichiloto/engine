<?php

namespace Ichiloto\Engine\Rendering\Transport;

use InvalidArgumentException;

final readonly class RendererProcessConfig
{
  /**
   * @param list<string> $command Executable followed by argv, passed directly to proc_open.
   */
  public function __construct(
    public array $command,
    public float $startupTimeout = 5.0,
    public float $shutdownTimeout = 3.0,
    public float $terminationTimeout = 0.25,
    public int $diagnosticBufferBytes = 16384,
    public int $maxOutboundBytes = 8388608,
    public int $maxLineBytes = 4194304,
    public int $maxPendingEvents = 1024,
    public int $maxPendingEventBytes = 8388608,
    public int $ioBudgetBytes = 65536,
  )
  {
    if (! array_is_list($command) || $command === [] || $command[0] === '') {
      throw new InvalidArgumentException('Renderer command must be a nonempty argv list.');
    }
    foreach ($command as $argument) {
      if (! is_string($argument) || str_contains($argument, "\0")) {
        throw new InvalidArgumentException('Renderer command arguments must be strings without NUL bytes.');
      }
    }
    foreach ([$startupTimeout, $shutdownTimeout, $terminationTimeout] as $timeout) {
      if (! is_finite($timeout) || $timeout <= 0.0 || $timeout > 60.0) {
        throw new InvalidArgumentException('Renderer timeouts must be finite, positive, and at most 60 seconds.');
      }
    }
    if ($diagnosticBufferBytes < 1 || $diagnosticBufferBytes > 1048576
      || $maxLineBytes < 128 || $maxLineBytes > 4194304
      || $maxOutboundBytes < $maxLineBytes || $maxOutboundBytes > 33554432
      || $maxPendingEvents < 1 || $maxPendingEvents > 65536
      || $maxPendingEventBytes < 128 || $maxPendingEventBytes > 33554432
      || $ioBudgetBytes < 1 || $ioBudgetBytes > 1048576) {
      throw new InvalidArgumentException('Renderer buffer limits are outside supported transport bounds.');
    }
  }
}
