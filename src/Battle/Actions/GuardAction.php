<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\ItemScope;

/**
 * Braces the actor: incoming damage is halved until their next turn.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class GuardAction extends BattleAction
{
  public ItemScope $targetScope {
    get { return new ItemScope(ItemScopeSide::USER); }
  }
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
