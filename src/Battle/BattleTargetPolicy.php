<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\SkillTargetPolicy;

/** Resolves battle recipients independently of command labels and rendering. */
final class BattleTargetPolicy
{
  /**
   * @param CharacterInterface[] $allies The actor's own side, including KO battlers.
   * @param CharacterInterface[] $opponents The opposing side, including KO battlers.
   * @return list<CharacterInterface>
   */
  public static function getEligibleTargets(
    ItemScope $scope,
    CharacterInterface $actor,
    array $allies,
    array $opponents,
    ?\Ichiloto\Engine\Battle\Formation\BattleFormation $formation = null,
    ?\Ichiloto\Engine\Battle\Formation\BattleTargeting $targeting = null,
  ): array
  {
    $pool = match ($scope->side) {
      ItemScopeSide::USER => [$actor],
      ItemScopeSide::ALLY => $allies,
      ItemScopeSide::ENEMY => $opponents,
      ItemScopeSide::ENEMY_ALLY => [...$allies, ...$opponents],
      ItemScopeSide::NONE => [],
    };
    $unique = [];
    foreach (SkillTargetPolicy::filterByStatus($pool, $scope->status) as $target) {
      $unique[spl_object_id($target)] = $target;
    }
    $eligible = array_values($unique);
    $shape = $targeting?->shape ?? \Ichiloto\Engine\Battle\Formation\BattleTargetShape::SCOPE;
    if ($shape !== \Ichiloto\Engine\Battle\Formation\BattleTargetShape::SCOPE && $formation === null) {
      throw new \InvalidArgumentException('Geometric battle targeting requires the current battle formation.');
    }
    return match ($shape) {
      \Ichiloto\Engine\Battle\Formation\BattleTargetShape::FRONT_ROW => $formation->getRowTargets(\Ichiloto\Engine\Battle\Formation\BattleRow::FRONT, $eligible),
      \Ichiloto\Engine\Battle\Formation\BattleTargetShape::BACK_ROW => $formation->getRowTargets(\Ichiloto\Engine\Battle\Formation\BattleRow::BACK, $eligible),
      default => $eligible,
    };
  }

  /**
   * Revalidates queued recipients without changing sides or widening one to all.
   * Random selections retain eligible instance identities rather than rerolling
   * because an effect or renderer inspects the command again.
   *
   * @param CharacterInterface[] $allies
   * @param CharacterInterface[] $opponents
   * @param CharacterInterface[] $preferred Already selected recipients, in order.
   * @return list<CharacterInterface>
   */
  public static function resolveTargets(
    ItemScope $scope,
    CharacterInterface $actor,
    array $allies,
    array $opponents,
    array $preferred = [],
    ?CombatRandomSource $random = null,
    ?\Ichiloto\Engine\Battle\Formation\BattleFormation $formation = null,
    ?\Ichiloto\Engine\Battle\Formation\BattleTargeting $targeting = null,
  ): array
  {
    $eligible = self::getEligibleTargets($scope, $actor, $allies, $opponents, $formation, $targeting);
    if ($eligible === [] || $scope->number === ItemScopeNumber::ALL) {
      return $eligible;
    }
    $selected = [];
    foreach ($preferred as $target) {
      if (in_array($target, $eligible, true)) {
        $selected[spl_object_id($target)] = $target;
      }
    }
    $selected = array_values($selected);
    if ($targeting?->shape === \Ichiloto\Engine\Battle\Formation\BattleTargetShape::LINE) {
      return $formation->getLineTargets($selected[0] ?? $eligible[0], $eligible);
    }
    if ($scope->number === ItemScopeNumber::ONE) {
      $target = $selected[0] ?? $eligible[0];

      return [$scope->side === ItemScopeSide::ENEMY && !$targeting?->ignoreCover ? self::applyCover($target, $eligible) : $target];
    }

    $count = max(1, min($scope->targetCount ?? 1, count($eligible)));
    $selected = array_slice($selected, 0, $count);
    $pool = array_values(array_filter(
      $eligible,
      static fn(CharacterInterface $target): bool => !in_array($target, $selected, true),
    ));
    $random ??= new NativeCombatRandomSource();
    while (count($selected) < $count && $pool !== []) {
      $index = $random->nextInt(0, count($pool) - 1);
      $selected[] = $pool[$index];
      array_splice($pool, $index, 1);
    }
    return $selected;
  }

  /**
   * Redirects a single-target hit meant for the weakest member of a side to a
   * member of that side who is covering it (Cover). The weakest member is the
   * one with the smallest share of its maximum HP; a coverer never covers itself.
   *
   * @param CharacterInterface $target The intended target.
   * @param CharacterInterface[] $side The target's side, as eligible for this action.
   * @return CharacterInterface The battler the hit lands on.
   */
  private static function applyCover(CharacterInterface $target, array $side): CharacterInterface
  {
    $coverer = array_find($side, static fn(CharacterInterface $member): bool => $member !== $target
      && $member->isFighting && method_exists($member, 'isCoveringAllies') && $member->isCoveringAllies());
    if ($coverer === null) {
      return $target;
    }

    $share = static fn(CharacterInterface $member): float => $member->stats->currentHp / max(1, $member->stats->totalHp);
    $covered = array_filter($side, static fn(CharacterInterface $member): bool => $member !== $coverer && $member->isFighting);
    usort($covered, static fn(CharacterInterface $a, CharacterInterface $b): int => $share($a) <=> $share($b));

    return ($covered[0] ?? null) === $target ? $coverer : $target;
  }
}
