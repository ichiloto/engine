<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The `adjust_income` script command: adds a one-time bonus or deduction
 * to an income schedule's next payment.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class AdjustIncomeCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    $context->scene->requireIncome()->adjust(strval($command['schedule'] ?? ''), intval($command['amount'] ?? 0));

    return ScriptCommandOutcome::complete();
  }
}
