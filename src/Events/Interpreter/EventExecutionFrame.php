<?php

namespace Ichiloto\Engine\Events\Interpreter;

/**
 * One command-list frame in a resumable event script.
 *
 * Branch and choice arms push frames instead of recursively running another
 * interpreter. The current index therefore survives every yielded command.
 */
final class EventExecutionFrame
{
  /**
   * @param array<int, array<string, mixed>> $commands The commands in this frame.
   * @param string $label A diagnostic label for this frame.
   */
  public function __construct(
    protected(set) array $commands,
    protected(set) string $label = 'script',
    protected(set) int $commandIndex = 0,
  )
  {
  }

  /** @return array<string, mixed>|null The current command. */
  public function currentCommand(): ?array
  {
    $command = $this->commands[$this->commandIndex] ?? null;

    return is_array($command) ? $command : null;
  }

  public function advance(): void
  {
    $this->commandIndex++;
  }

  public function isComplete(): bool
  {
    return $this->commandIndex >= count($this->commands);
  }
}
