<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Util\Debug;

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
 * Unknown types fail closed and warn in debug logs so an authoring typo can
 * never expose guarded content.
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

      $typeValue = strval($condition['type'] ?? '');
      $type = WorldConditionType::tryFrom($typeValue);

      if (! $type instanceof WorldConditionType) {
        Debug::warn(sprintf(
          'Unknown world condition type "%s" for "%s"; guarded content was not exposed.',
          $typeValue,
          strval($condition['name'] ?? '(unnamed)')
        ));

        return false;
      }

      $name = trim(strval($condition['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      $result = self::evaluate($type, $condition, $name, $gameState, $party);

      if (($condition['negate'] ?? false) ? $result : ! $result) {
        return false;
      }
    }

    return true;
  }

  /**
   * Evaluates a single condition, ignoring its `negate` flag.
   *
   * @param WorldConditionType $type The recognized condition type.
   * @param array<string, mixed> $condition The condition entry.
   * @param string $name The trimmed condition name.
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @return bool True when the condition's test passes.
   */
  protected static function evaluate(
    WorldConditionType $type,
    array $condition,
    string $name,
    GameState $gameState,
    ?Party $party
  ): bool
  {
    return match ($type) {
      WorldConditionType::SWITCH => $gameState->getSwitch($name) === (bool) ($condition['value'] ?? true),
      WorldConditionType::EVENT => $gameState->hasStoryEvent($name),
      WorldConditionType::VARIABLE => self::compare(
        $gameState->getVariable($name),
        strval($condition['op'] ?? '=='),
        $condition['value'] ?? 0
      ),
      WorldConditionType::ITEM => ($party?->inventory?->getQuantity($name, 'evaluating an item world condition') ?? 0) >= max(1, intval($condition['quantity'] ?? 1)),
      WorldConditionType::KEY_ITEM => $party?->inventory?->hasKeyItem($name) ?? false,
      WorldConditionType::QUEST => QuestManager::current()?->questStatusMatches($name, strval($condition['status'] ?? 'completed')) ?? false,
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
