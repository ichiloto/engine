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
    return array_values($unique);
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
  ): array
  {
    $eligible = self::getEligibleTargets($scope, $actor, $allies, $opponents);
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
    if ($scope->number === ItemScopeNumber::ONE) {
      return [$selected[0] ?? $eligible[0]];
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
}
