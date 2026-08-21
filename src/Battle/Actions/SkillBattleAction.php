<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\Skills\SkillRollLedger;

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
    ?CombatResolver $resolver = null,
    ?CombatRandomSource $random = null,
  )
  {
    parent::__construct($skill->name, $resolver, $random);
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
    $orderedTargetIds = [];
    $hitsByTarget = [];
    $secondaryByTarget = [];
    $rollLedger = new SkillRollLedger();

    foreach ($targets as $target) {
      if (! $target instanceof Actor) {
        continue;
      }

      $context = new SkillEffectContext(
        $actor,
        $target,
        $this->resolver,
        $this->random,
        $actionId,
        $executionId,
        $this->skill->invocation->accuracy > 0 ? $this->skill->invocation->accuracy : null,
        $this->skill instanceof MagicSkill ? ResolutionKind::MAGICAL_DAMAGE : ResolutionKind::PHYSICAL_DAMAGE,
        $this->skill->invocation->hitScope,
        $this->skill->invocation->criticalScope,
        $rollLedger,
      );

      foreach ($this->skill->effects as $effect) {
        $applications = $effect->repeatsWithInvocation()
          ? max(1, $this->skill->invocation->repeat)
          : 1;

        for ($repeat = 0; $repeat < $applications; $repeat++) {
          $effect->apply($context);
        }
      }

      foreach ($context->results() as $hit) {
        if (! isset($hitsByTarget[$hit->targetId])) {
          $orderedTargetIds[] = $hit->targetId;
          $hitsByTarget[$hit->targetId] = [];
          $secondaryByTarget[$hit->targetId] = [];
        }

        $hitsByTarget[$hit->targetId][] = $hit;
      }

      $targetId = CombatResolver::identity($target);
      if (! isset($hitsByTarget[$targetId])) {
        $orderedTargetIds[] = $targetId;
        $hitsByTarget[$targetId] = [];
        $secondaryByTarget[$targetId] = [];
      }
      $secondaryByTarget[$targetId] = [
        ...$secondaryByTarget[$targetId],
        ...$context->secondaryOutcomes(),
      ];
    }

    $targetResults = array_map(
      static fn(string $targetId): CombatTargetResult => new CombatTargetResult(
        $targetId,
        $hitsByTarget[$targetId],
        $secondaryByTarget[$targetId],
      ),
      $orderedTargetIds,
    );

    $this->lastResult = new CombatActionResult(
      $actionId,
      $executionId,
      CombatResolver::identity($actor),
      $targetResults,
    );
  }
}
