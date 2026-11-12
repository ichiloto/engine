<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The `payday` script command: pays one period of an income schedule now,
 * so a game's own calendar, day cycle or story beats can decide when pay
 * arrives. Writes the pay to `resultVariable` when one is given.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class PaydayCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    $paid = $context->scene->requireIncome()->payday(strval($command['schedule'] ?? ''));
    IncomeCommandResult::write($context, $command, $paid);

    return ScriptCommandOutcome::complete();
  }
}
