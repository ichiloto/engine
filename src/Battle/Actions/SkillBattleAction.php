<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillEffectExecutor;

/**
 * Executes a battle skill by applying each of its configured effects.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class SkillBattleAction extends BattleAction
{
  private SkillEffectExecutor $effectExecutor;

  /**
   * @param Skill $skill The skill wrapped by this battle action.
   */
  public function __construct(
    protected(set) Skill $skill,
    ?CombatResolver $resolver = null,
    ?CombatRandomSource $random = null,
  )
  {
    parent::__construct($skill->name, $resolver, $random);
    $this->effectExecutor = new SkillEffectExecutor($this->resolver, $this->random);
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
    $executionId = $this->nextExecutionId();
    $actionId = 'skill.' . trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $this->skill->name) ?? ''), '-');
    $this->lastResult = $this->effectExecutor->execute(
      $this->skill,
      $actor,
      $targets,
      $actionId,
      $executionId,
    );
  }
}
