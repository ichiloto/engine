<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Quests\QuestManager;

/**
 * Evaluates world-state condition lists.
 *
 * One shared implementation for every surface that gates on the world:
 * event triggers, quest prerequisites, event-script branches, NPC
 * visibility, and skit availability.
 *
 * Supported shapes (all accept `'negate' => true` to invert the result):
 * - `['type' => 'switch',   'name' => 'gate_open', 'value' => true]`
 * - `['type' => 'event',    'name' => 'met_the_king']`
 * - `['type' => 'variable', 'name' => 'donations', 'op' => '>=', 'value' => 100]`
 * - `['type' => 'item',     'name' => 'Potion', 'quantity' => 2]`
 * - `['type' => 'key_item', 'name' => 'Rusty Key']`
 * - `['type' => 'quest',    'name' => 'quest-id', 'status' => 'completed'|'active']`
 *
 * An unknown type passes, so authoring a typo never silently hides content.
 *
 * @package Ichiloto\Engine\Core
 */
class WorldConditionEvaluator
{
  /**
   * Determines whether every condition in the list holds.
   *
   * @param array<int, mixed> $conditions The condition entries.
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @return bool True when all conditions hold.
   */
  public static function allHold(array $conditions, GameState $gameState, ?Party $party = null): bool
  {
    foreach ($conditions as $condition) {
      if (! is_array($condition)) {
        continue;
      }

      $name = trim(strval($condition['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      $result = self::evaluate($condition, $name, $gameState, $party);

      if (($condition['negate'] ?? false) ? $result : ! $result) {
        return false;
      }
    }

    return true;
  }

  /**
   * Evaluates a single condition, ignoring its `negate` flag.
   *
   * @param array<string, mixed> $condition The condition entry.
   * @param string $name The trimmed condition name.
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @return bool True when the condition's test passes.
   */
  protected static function evaluate(
    array $condition,
    string $name,
    GameState $gameState,
    ?Party $party
  ): bool
  {
    return match (strval($condition['type'] ?? '')) {
      'switch' => $gameState->getSwitch($name) === (bool) ($condition['value'] ?? true),
      'event' => $gameState->hasStoryEvent($name),
      'variable' => self::compare(
        $gameState->getVariable($name),
        strval($condition['op'] ?? '=='),
        $condition['value'] ?? 0
      ),
      'item' => ($party?->inventory?->getQuantityByName($name) ?? 0) >= max(1, intval($condition['quantity'] ?? 1)),
      'key_item' => $party?->inventory?->hasKeyItem($name) ?? false,
      'quest' => QuestManager::current()?->questStatusMatches($name, strval($condition['status'] ?? 'completed')) ?? false,
      default => true,
    };
  }

  /**
   * Compares a variable value against an expectation.
   *
   * @param int|float|string $actual The stored value.
   * @param string $operator The comparison operator.
   * @param mixed $expected The expected value.
   * @return bool True when the comparison holds.
   */
  public static function compare(int|float|string $actual, string $operator, mixed $expected): bool
  {
    return match ($operator) {
      '!=' => $actual != $expected,
      '>' => $actual > $expected,
      '>=' => $actual >= $expected,
      '<' => $actual < $expected,
      '<=' => $actual <= $expected,
      default => $actual == $expected,
    };
  }
}
