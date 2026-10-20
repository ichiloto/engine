<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use RuntimeException;

/**
 * The `concede` battle command: a troop member stops fighting and counts as
 * defeated, staying on screen in its conceding presentation.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class ConcedeCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    ($context->battle ?? throw new RuntimeException('concede runs only in battle events.'))
      ->concedeEnemy(intval($command['enemy']));

    return ScriptCommandOutcome::complete();
  }
}
