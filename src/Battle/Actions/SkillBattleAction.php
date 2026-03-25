<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Executes a battle skill by applying each of its configured effects.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class SkillBattleAction extends BattleAction
{
  /**
   * @param Skill $skill The skill wrapped by this battle action.
   */
  public function __construct(
    protected(set) Skill $skill,
  )
  {
    parent::__construct($skill->name);
  }

  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    if ($actor->isKnockedOut || $actor->stats->currentMp < $this->skill->cost) {
      return;
    }

    $actor->stats->currentMp -= $this->skill->cost;

    foreach ($targets as $target) {
      if (! $target instanceof Actor) {
        continue;
      }

      $context = new SkillEffectContext($actor, $target);

      foreach ($this->skill->effects as $effect) {
        $effect->apply($context);
      }
    }
  }
}
