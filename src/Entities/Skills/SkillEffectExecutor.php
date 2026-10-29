<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatResourceChange;
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
    ?\Ichiloto\Engine\Battle\BattleReactions $reactions = null,
    ?\Ichiloto\Engine\Battle\BattleGaugeControl $gauges = null,
    ?\Ichiloto\Engine\Battle\Formation\BattleFormation $formation = null,
  ): CombatActionResult
  {
    $orderedTargetIds = [];
    $hitsByTarget = [];
    $secondaryByTarget = [];
    $resourcesByTarget = [];
    $rollLedger = new SkillRollLedger();
    $groupContexts = [];
    $groupEffects = array_values(array_filter($skill->effects, static fn($effect): bool =>
      $effect instanceof \Ichiloto\Engine\Entities\Effects\SkillEffects\GroupSkillEffect));
    foreach ($groupEffects as $effect) { $effect->validateContext($formation, $targets); }

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
        $reactions,
        $gauges,
      );

      foreach ($skill->effects as $effect) {
        if ($effect instanceof \Ichiloto\Engine\Entities\Effects\SkillEffects\GroupSkillEffect) { continue; }
        $applications = $effect->repeatsWithInvocation()
          ? max(1, $skill->invocation->repeat)
          : 1;

        for ($repeat = 0; $repeat < $applications; $repeat++) {
          $recipients = $actor === $target ? [$target] : [$target, $actor];
          $previousVitals = array_map(static fn(CharacterInterface $battler): array =>
            [$battler->stats->currentHp, $battler->stats->currentMp], $recipients);
          $effect->apply($context);
          foreach ($recipients as $index => $recipient) {
            $change = CombatResourceChange::measure($recipient, ...$previousVitals[$index]);
            if ($recipient !== $target && !$change->hasChanges) { continue; }
            $id = CombatResolver::identity($recipient);
            $resourcesByTarget[$id] = ($resourcesByTarget[$id] ?? new CombatResourceChange())->accumulate($change);
          }
        }
      }

      $groupContexts[spl_object_id($target)][] = $context;
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

    $groupContext = new SkillGroupEffectContext($actor, $groupContexts, $this->random, $formation);
    foreach ($groupEffects as $effect) { $effect->applyToGroup($groupContext); }
    foreach ($groupContext->getOutcomes() as $targetId => $outcomes) {
      $secondaryByTarget[$targetId] = [...($secondaryByTarget[$targetId] ?? []), ...$outcomes];
    }

    foreach ($resourcesByTarget as $targetId => $change) {
      if (isset($hitsByTarget[$targetId])) { continue; }
      $orderedTargetIds[] = $targetId;
      $hitsByTarget[$targetId] = [];
      $secondaryByTarget[$targetId] = [];
    }

    $targetResults = array_map(
      static fn(string $targetId): CombatTargetResult => new CombatTargetResult(
        $targetId,
        $hitsByTarget[$targetId],
        $secondaryByTarget[$targetId],
        $resourcesByTarget[$targetId] ?? new CombatResourceChange(),
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
