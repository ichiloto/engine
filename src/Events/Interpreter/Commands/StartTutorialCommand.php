<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use InvalidArgumentException;

/** Leaves the event lane free to release field input before a lesson begins. */
final class StartTutorialCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    $id = $command['tutorial'] ?? null;
    if ($context->battle !== null || !is_string($id) || $context->scene->tutorialDirector === null) {
      throw new InvalidArgumentException('start_tutorial queues a declared lesson from a field script.');
    }

    $context->scene->tutorialDirector->queueScriptTutorial($id);
    return ScriptCommandOutcome::complete();
  }
}
