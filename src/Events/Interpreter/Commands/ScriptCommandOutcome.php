<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;

/**
 * What a registered command asks of its script once it has run.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final readonly class ScriptCommandOutcome
{
  /**
   * @param EventPendingOperationInterface|null $operation The operation the script waits for, or null to continue now.
   */
  private function __construct(
    public ?EventPendingOperationInterface $operation,
  )
  {
  }

  /** The command is done; the script continues in the same frame. */
  public static function complete(): self
  {
    return new self(null);
  }

  /**
   * The script waits, one field tick at a time, until the operation reports
   * it is done. The operation is cancelled if the script is abandoned first.
   */
  public static function waitFor(EventPendingOperationInterface $operation): self
  {
    return new self($operation);
  }
}
