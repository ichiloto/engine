<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Enemies\ActionCondition;
use Ichiloto\Engine\Entities\Enemies\ActionPattern;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;
use Ichiloto\Engine\Entities\Skills\Skill;

/**
 * Chooses an enemy's action from its authored action patterns.
 *
 * RPG-Maker semantics: every pattern whose condition holds (and whose skill
 * the enemy can afford) is a candidate; among candidates, patterns rated
 * within 2 of the best stay in the pool and are picked weighted by
 * `rating - (best - 3)`.
 *
 * @package Ichiloto\Engine\Battle
 */
class EnemyActionEvaluator
{
  /**
   * Returns the patterns the enemy could use right now.
   *
   * @param ActionPattern[] $patterns The enemy's authored patterns.
   * @param object $enemy The acting enemy.
   * @param int $roundNumber The 1-based battle round.
   * @param int $maxPartyLevel The highest level in the player party.
   * @param callable|null $switchLookup Resolves a switch name to a bool; null treats switch conditions as failed.
   * @return ActionPattern[] The usable patterns.
   */
  public static function filterUsablePatterns(
    array $patterns,
    object $enemy,
    int $roundNumber,
    int $maxPartyLevel,
    ?callable $switchLookup = null
  ): array
  {
    return array_values(array_filter(
      $patterns,
      static function (ActionPattern $pattern) use ($enemy, $roundNumber, $maxPartyLevel, $switchLookup): bool {
        $skill = $pattern->skill;

        if ($skill instanceof Skill && $skill->cost > $enemy->stats->currentMp) {
          return false;
        }

        return self::conditionHolds($pattern->condition, $enemy, $roundNumber, $maxPartyLevel, $switchLookup);
      }
    ));
  }

  /**
   * Picks one pattern from the usable pool, weighted by rating.
   *
   * @param ActionPattern[] $patterns The usable patterns.
   * @return ActionPattern|null The chosen pattern.
   */
  public static function pickPattern(array $patterns): ?ActionPattern
  {
    if (empty($patterns)) {
      return null;
    }

    $bestRating = max(array_map(static fn(ActionPattern $pattern): int => $pattern->rating, $patterns));
    $floor = $bestRating - 3;
    $pool = array_values(array_filter(
      $patterns,
      static fn(ActionPattern $pattern): bool => $pattern->rating > $floor
    ));

    $totalWeight = 0;

    foreach ($pool as $pattern) {
      $totalWeight += $pattern->rating - $floor;
    }

    $roll = rand(1, max(1, $totalWeight));

    foreach ($pool as $pattern) {
      $roll -= $pattern->rating - $floor;

      if ($roll <= 0) {
        return $pattern;
      }
    }

    return $pool[count($pool) - 1];
  }

  /**
   * Evaluates one pattern condition.
   *
   * @param ActionCondition $condition The condition.
   * @param object $enemy The acting enemy.
   * @param int $roundNumber The 1-based battle round.
   * @param int $maxPartyLevel The highest level in the player party.
   * @param callable|null $switchLookup Resolves a switch name to a bool.
   * @return bool True when the condition holds.
   */
  public static function conditionHolds(
    ActionCondition $condition,
    object $enemy,
    int $roundNumber,
    int $maxPartyLevel,
    ?callable $switchLookup = null
  ): bool
  {
    return match ($condition->type) {
      ActionConditionType::ALWAYS => true,
      // Turn conditions read "turn a + b×n": with no interval the round must
      // equal a; with one, every b-th round from a onward matches.
      ActionConditionType::TURN => $condition->b > 0
        ? $roundNumber >= $condition->a && ($roundNumber - $condition->a) % $condition->b === 0
        : $roundNumber === max(1, $condition->a),
      ActionConditionType::HP => self::percentWithin(
        $enemy->stats->currentHp,
        $enemy->stats->totalHp,
        $condition
      ),
      ActionConditionType::MP => self::percentWithin(
        $enemy->stats->currentMp,
        $enemy->stats->totalMp,
        $condition
      ),
      ActionConditionType::Status => is_string($condition->status)
        && method_exists($enemy, 'hasState')
        && $enemy->hasState($condition->status),
      ActionConditionType::PARTY_LEVEL => $maxPartyLevel >= $condition->partyLevel,
      ActionConditionType::SWITCH => is_string($condition->status)
        && $switchLookup !== null
        && (bool) $switchLookup($condition->status),
    };
  }

  /**
   * Determines whether current/total falls inside the condition's range.
   *
   * @param int $current The current value.
   * @param int $total The total value.
   * @param ActionCondition $condition The condition carrying the range.
   * @return bool True when the percentage is inside the range.
   */
  protected static function percentWithin(int $current, int $total, ActionCondition $condition): bool
  {
    if ($total < 1) {
      return false;
    }

    $percent = ($current / $total) * 100;

    return $percent >= $condition->range->min && $percent <= $condition->range->max;
  }
}
