<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Inn\InnOffer;
use Ichiloto\Engine\Inn\InnStay;

/**
 * The `inn` script command: offers the party a stay, and records how it
 * ended in `resultVariable` when one is given (`stayed`, `declined` or
 * `unaffordable`), so the script can answer accordingly.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class InnCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    $outcome = new InnStay(InnOffer::fromData($command))->perform($context->scene);
    $resultVariable = trim(strval($command['resultVariable'] ?? ''));

    if ($resultVariable !== '') {
      $context->scene->gameState->setVariable($resultVariable, $outcome->value);
    }

    return ScriptCommandOutcome::complete();
  }
}
