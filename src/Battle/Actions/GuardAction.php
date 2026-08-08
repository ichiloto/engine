<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;

/**
 * Braces the actor: incoming damage is halved until their next turn.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class GuardAction extends BattleAction
{
  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    if ($actor->isKnockedOut) {
      return;
    }

    if (method_exists($actor, 'beginGuarding')) {
      $actor->beginGuarding();
    }
  }
}
