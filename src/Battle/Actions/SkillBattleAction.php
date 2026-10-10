<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillEffectExecutor;
use Ichiloto\Engine\Localization\Vocabulary;

/**
 * Executes a battle skill by applying each of its configured effects.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class SkillBattleAction extends BattleAction implements ExecutionEligibility
{
  private SkillEffectExecutor $effectExecutor;
  public ItemScope $targetScope {
    get { return clone $this->skill->scope; }
  }

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

  public function getExecutionRefusal(Actor $actor): ?string
  {
    if ($actor->isKnockedOut) {
      return sprintf('%s cannot act while knocked out.', $actor->name);
    }
    return $actor->stats->currentMp < $this->skill->cost
      ? get_message('battle.insufficient_resource', '%1 cannot use %2: not enough %3.',
        $actor->name, $this->name, Vocabulary::getTerm('stats.mp', 'MP'))
      : null;
  }

  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    if ($this->getExecutionRefusal($actor) !== null) {
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
