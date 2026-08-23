<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Party;
use Throwable;

/**
 * Resolves and executes a magic skill used outside battle.
 *
 * The request is transactional with respect to MP: cancellation happens in
 * the menu before this boundary, and rejected or ineffective requests never
 * retain their MP cost.
 */
final class FieldSkillExecutor
{
  private SkillEffectExecutor $effectExecutor;
  private CombatRandomSource $random;
  private int $executionSequence = 0;

  public function __construct(
    ?CombatResolver $resolver = null,
    ?CombatRandomSource $random = null,
  )
  {
    $this->random = $random ?? new NativeCombatRandomSource();
    $this->effectExecutor = new SkillEffectExecutor($resolver, $this->random);
  }

  /**
   * Executes a field skill after resolving its authored scope against the party.
   */
  public function execute(
    MagicSkill $skill,
    Character $caster,
    Party $party,
    ?Character $selectedTarget = null,
  ): FieldSkillExecutionResult
  {
    if (! in_array($skill->occasion, [Occasion::ALWAYS, Occasion::MENU_SCREEN], true)) {
      return $this->failure(FieldSkillFailureReason::WRONG_OCCASION);
    }

    if ($caster->isKnockedOut) {
      return $this->failure(FieldSkillFailureReason::CASTER_KNOCKED_OUT);
    }

    if ($caster->stats->currentMp < $skill->cost) {
      return $this->failure(FieldSkillFailureReason::INSUFFICIENT_MP);
    }

    if ($skill->effects === []) {
      return $this->failure(FieldSkillFailureReason::NO_EFFECTS);
    }

    $targetResolution = $this->resolveTargets($skill, $caster, $party, $selectedTarget);
    if ($targetResolution instanceof FieldSkillFailureReason) {
      return $this->failure($targetResolution);
    }

    $targets = $targetResolution;
    $observedCharacters = $this->uniqueCharacters([$caster, ...$targets]);
    $caster->stats->currentMp -= $skill->cost;
    $baseline = $this->snapshot($observedCharacters);
    $actionId = 'field-skill.' . $this->slug($skill->name);
    $executionId = sprintf('%s:%d', $actionId, ++$this->executionSequence);

    try {
      $actionResult = $this->effectExecutor->execute(
        $skill,
        $caster,
        $targets,
        $actionId,
        $executionId,
      );
    } catch (Throwable $throwable) {
      $caster->stats->currentMp += $skill->cost;
      throw $throwable;
    }

    if ($this->snapshot($observedCharacters) === $baseline) {
      $caster->stats->currentMp += $skill->cost;
      return $this->failure(FieldSkillFailureReason::NO_EFFECT, $targets, $actionResult);
    }

    return new FieldSkillExecutionResult(true, $targets, actionResult: $actionResult);
  }

  /**
   * @return Character[]|FieldSkillFailureReason
   */
  private function resolveTargets(
    MagicSkill $skill,
    Character $caster,
    Party $party,
    ?Character $selectedTarget,
  ): array|FieldSkillFailureReason
  {
    if ($skill->scope->side === ItemScopeSide::USER) {
      return SkillTargetPolicy::matchesStatus($caster, $skill->scope->status)
        ? [$caster]
        : FieldSkillFailureReason::NO_ELIGIBLE_TARGETS;
    }

    if ($skill->scope->side !== ItemScopeSide::ALLY) {
      return FieldSkillFailureReason::UNSUPPORTED_SCOPE;
    }

    /** @var Character[] $eligibleTargets */
    $eligibleTargets = SkillTargetPolicy::filterByStatus(
      $party->members->toArray(),
      $skill->scope->status,
    );

    if ($eligibleTargets === []) {
      return FieldSkillFailureReason::NO_ELIGIBLE_TARGETS;
    }

    return match ($skill->scope->number) {
      ItemScopeNumber::ONE => $this->resolveSelectedTarget($eligibleTargets, $selectedTarget),
      ItemScopeNumber::ALL => $eligibleTargets,
      ItemScopeNumber::RANDOM => $this->resolveRandomTargets($eligibleTargets, $skill->scope->targetCount),
    };
  }

  /**
   * @param Character[] $eligibleTargets
   * @return Character[]|FieldSkillFailureReason
   */
  private function resolveSelectedTarget(
    array $eligibleTargets,
    ?Character $selectedTarget,
  ): array|FieldSkillFailureReason
  {
    if (! $selectedTarget instanceof Character) {
      return FieldSkillFailureReason::TARGET_REQUIRED;
    }

    return in_array($selectedTarget, $eligibleTargets, true)
      ? [$selectedTarget]
      : FieldSkillFailureReason::INVALID_TARGET;
  }

  /**
   * @param Character[] $eligibleTargets
   * @return Character[]
   */
  private function resolveRandomTargets(array $eligibleTargets, ?int $targetCount): array
  {
    $count = intval(clamp($targetCount ?? 1, 1, count($eligibleTargets)));
    $pool = array_values($eligibleTargets);
    $targets = [];

    while (count($targets) < $count && $pool !== []) {
      $index = $this->random->nextInt(0, count($pool) - 1);
      $targets[] = $pool[$index];
      array_splice($pool, $index, 1);
    }

    return $targets;
  }

  /**
   * @param Character[] $characters
   * @return Character[]
   */
  private function uniqueCharacters(array $characters): array
  {
    $unique = [];

    foreach ($characters as $character) {
      $unique[spl_object_id($character)] = $character;
    }

    return array_values($unique);
  }

  /**
   * Captures values rather than retaining references to mutable nested objects.
   *
   * Character::toArray() intentionally contains objects such as Stats. Keeping
   * that array directly would make the "before" snapshot change alongside the
   * live character and every successful effect would appear ineffective.
   *
   * @param Character[] $characters
   * @return array<int, string>
   */
  private function snapshot(array $characters): array
  {
    $snapshot = [];

    foreach ($characters as $character) {
      $snapshot[spl_object_id($character)] = serialize($character->toArray());
    }

    return $snapshot;
  }

  private function failure(
    FieldSkillFailureReason $reason,
    array $targets = [],
    ?\Ichiloto\Engine\Battle\Resolution\CombatActionResult $actionResult = null,
  ): FieldSkillExecutionResult
  {
    return new FieldSkillExecutionResult(false, $targets, $reason, $actionResult);
  }

  private function slug(string $value): string
  {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? 'skill'), '-');
  }
}
