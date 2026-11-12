<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * Decides what a confused battler does on its turn. A state with a
 * confusion chance (Fog, or its lighter Dazed) rolls it as the turn begins:
 * on a hit the battler cannot tell friend from foe and makes its ordinary
 * attack on a random battler still fighting on either side, never itself,
 * in place of the action it chose. Every turn path asks here (the live
 * battle and the battle simulator), so balance tools measure the turn the
 * game plays.
 *
 * @package Ichiloto\Engine\Battle
 */
final class BattleConfusion
{
  private function __construct() {}

  /**
   * @param CharacterInterface $battler The battler whose turn it is.
   * @param CharacterInterface[] $allies Its side, including knocked-out battlers.
   * @param CharacterInterface[] $opponents The other side, including knocked-out battlers.
   * @return array{0: BattleAction, 1: list<CharacterInterface>}|null The attack and its one target, or null when the battler
   *   acts as it chose this turn.
   */
  public static function resolve(
    CharacterInterface $battler,
    array $allies,
    array $opponents,
    CombatRandomSource $random,
    ?CombatResolver $resolver = null,
  ): ?array
  {
    $chance = method_exists($battler, 'getConfusionChancePercent') ? $battler->getConfusionChancePercent() : 0;
    if ($chance < 1 || $random->nextInt(1, 100) > $chance) {
      return null;
    }
    $candidates = [];
    foreach ([...$allies, ...$opponents] as $candidate) {
      if ($candidate instanceof CharacterInterface && $candidate !== $battler && $candidate->isFighting) {
        $candidates[spl_object_id($candidate)] = $candidate;
      }
    }
    if ($candidates === []) {
      return null;
    }
    $candidates = array_values($candidates);
    $target = $candidates[$random->nextInt(0, count($candidates) - 1)];

    return [InherentAttack::createAction($battler, $resolver, $random), [$target]];
  }
}
