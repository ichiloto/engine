<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Battle\Events\BattleOutcome;
use RuntimeException;

/**
 * The `end_battle` battle command: the battle ends with the authored outcome
 * once the running page finishes.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class EndBattleCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    ($context->battle ?? throw new RuntimeException('end_battle runs only in battle events.'))
      ->requestOutcome(BattleOutcome::from(strval($command['outcome'])));

    return ScriptCommandOutcome::complete();
  }
}
