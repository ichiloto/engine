<?php

namespace Ichiloto\Engine\Events\Interpreter;

/** A cooperative, cancellable operation owned by one event lane. */
interface EventPendingOperationInterface
{
  public function update(float $deltaSeconds): bool;

  public function cancel(): void;
}
