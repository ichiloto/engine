<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

/** A pending operation whose terminal result can resume its lane with commands. */
interface EventPendingContinuationInterface extends EventPendingOperationInterface
{
  /**
   * Called after update() returns true. The operation selects the commands
   * for its result, with an empty list to resume the next authored command.
   *
   * @return list<array<string, mixed>>
   */
  public function getContinuationCommands(): array;
}
