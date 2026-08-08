<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattlerBattleView;
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
    $actorView = new BattlerBattleView($actor);

    foreach ($targets as $target) {
      if (! $target instanceof Actor) {
        continue;
      }

      // Accuracy 0 marks a guaranteed action (buffs, cures, old data);
      // anything else rolls to hit against the target's evasion.
      $accuracy = $this->skill->invocation->accuracy;

      if ($accuracy > 0) {
        $hitChance = intval(clamp(
          $accuracy + $actorView->stats->grace - new BattlerBattleView($target)->stats->evasion,
          5,
          100
        ));

        if (rand(1, 100) > $hitChance) {
          continue; // The battle UI reads the unchanged stats as a MISS.
        }
      }

      $context = new SkillEffectContext($actor, $target);

      // Grace sharpens the critical rate.
      $critChance = intval(clamp(5 + intdiv($actorView->stats->grace, 10), 1, 50));

      if (rand(1, 100) <= $critChance) {
        $context->criticalHit = true;

        if (method_exists($target, 'addState')) {
          $target->lastHitWasCritical = true;
        }
      }

      foreach ($this->skill->effects as $effect) {
        $effect->apply($context);
      }
    }
  }
}
