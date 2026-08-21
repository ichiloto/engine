<?php

namespace Ichiloto\Engine\Events\Interpreter;

use InvalidArgumentException;

/** The child lanes owned by one authored parallel command. */
final class EventParallelGroup
{
  /** @var EventExecutionLane[] */
  protected array $lanes = [];

  /** @param array<int, mixed> $entries */
  public function __construct(array $entries, string $parentPath)
  {
    if (! array_is_list($entries) || $entries === []) {
      throw new InvalidArgumentException('Parallel command lanes must be a non-empty list.');
    }

    $ids = [];

    foreach (array_values($entries) as $index => $entry) {
      $id = (string) $index;
      $commands = $entry;

      if (is_array($entry) && ! array_is_list($entry)) {
        $id = trim(strval($entry['id'] ?? $index));
        $commands = $entry['commands'] ?? [];
      }

      if ($id === '') {
        throw new InvalidArgumentException(sprintf('Parallel lane %d requires a non-empty id.', $index + 1));
      }

      if (isset($ids[$id])) {
        throw new InvalidArgumentException(sprintf('Parallel command has duplicate lane id "%s".', $id));
      }

      $ids[$id] = true;

      if (! is_array($commands) || ! array_is_list($commands) || $commands === []) {
        throw new InvalidArgumentException(sprintf('Parallel lane "%s" must contain a non-empty command list.', $id));
      }

      foreach ($commands as $commandIndex => $command) {
        if (! is_array($command) || array_is_list($command)) {
          throw new InvalidArgumentException(sprintf(
            'Parallel lane "%s" command %d must be a keyed command array.',
            $id,
            $commandIndex + 1,
          ));
        }
      }

      $path = sprintf('%s/parallel[%s]', $parentPath, $id);
      $this->lanes[] = new EventExecutionLane($commands, $path, sprintf('parallel:%s', $id));
    }
  }

  /** @return EventExecutionLane[] */
  public function lanes(): array
  {
    return $this->lanes;
  }

  public function isComplete(): bool
  {
    foreach ($this->lanes as $lane) {
      if ($lane->status !== EventExecutionStatus::COMPLETED) {
        return false;
      }
    }

    return true;
  }

  public function cancel(): void
  {
    foreach ($this->lanes as $lane) {
      $lane->cancel();
    }
  }
}
