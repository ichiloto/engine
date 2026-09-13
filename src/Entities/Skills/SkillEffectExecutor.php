<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * Executes configured skill effects independently of battle or menu policy.
 *
 * Resource costs, target selection and occasion rules belong to the caller.
 * This class is the single mutation path for the effects themselves.
 */
final class SkillEffectExecutor
{
  public function __construct(
    private ?CombatResolver $resolver = null,
    private ?CombatRandomSource $random = null,
  )
  {
    $this->resolver ??= new CombatResolver();
    $this->random ??= new NativeCombatRandomSource();
  }

  /**
   * @param CharacterInterface[] $targets The resolved skill targets.
   */
  public function execute(
    Skill $skill,
    CharacterInterface $actor,
    array $targets,
    string $actionId,
    string $executionId,
  ): CombatActionResult
  {
    $orderedTargetIds = [];
    $hitsByTarget = [];
    $secondaryByTarget = [];
    $rollLedger = new SkillRollLedger();

    foreach ($targets as $target) {
      if (! $target instanceof CharacterInterface) {
        continue;
      }

      $context = new SkillEffectContext(
        $actor,
        $target,
        $this->resolver,
        $this->random,
        $actionId,
        $executionId,
        $skill->invocation->accuracy > 0 ? $skill->invocation->accuracy : null,
        $skill instanceof MagicSkill ? ResolutionKind::MAGICAL_DAMAGE : ResolutionKind::PHYSICAL_DAMAGE,
        $skill->invocation->hitScope,
        $skill->invocation->criticalScope,
        $rollLedger,
      );

      foreach ($skill->effects as $effect) {
        $applications = $effect->repeatsWithInvocation()
          ? max(1, $skill->invocation->repeat)
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

    return new CombatActionResult(
      $actionId,
      $executionId,
      CombatResolver::identity($actor),
      $targetResults,
    );
  }
}
