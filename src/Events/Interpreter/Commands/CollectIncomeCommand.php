<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The `collect_income` script command: moves an income schedule's held pay
 * into party gold. Writes the amount collected to `resultVariable` when one
 * is given, so the script can answer "nothing waiting" in its own words.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class CollectIncomeCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    $collected = $context->scene->requireIncome()->collect(strval($command['schedule'] ?? ''));
    IncomeCommandResult::write($context, $command, $collected);

    return ScriptCommandOutcome::complete();
  }
}
