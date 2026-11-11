<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

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
 * - `['type' => 'any',      'conditions' => [$first, $second]]`
 * - `['type' => 'map_event', 'name' => 'town/home', 'marker' => 'A']`
 * - `['type' => 'variable', 'name' => 'donations', 'op' => '>=', 'value' => 100]`
 * - `['type' => 'play_statistic', 'name' => 'battles_won', 'op' => '>=', 'value' => 1]`
 * - `['type' => 'item',     'name' => 'Potion', 'quantity' => 2]`
 * - `['type' => 'key_item', 'name' => 'Rusty Key']`
 * - `['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 2]`
 * - `['type' => 'quest',    'name' => 'quest-id', 'status' => 'completed'|'active']`
 *
 * Unknown types fail closed and warn in debug logs so an authoring typo can
 * never expose guarded content.
 *
 * @package Ichiloto\Engine\Core
 */
class WorldConditionEvaluator
{
  private const int MAX_ANY_DEPTH = 4;
  private const int MAX_ANY_MEMBERS = 32;
  private const int MAX_ANY_TREE_MEMBERS = 256;

  /**
   * Validates authored conditions without evaluating or mutating runtime state.
   *
   * @param array<int, mixed> $conditions
   */
  public static function validateAll(array $conditions, string $source = 'world conditions'): void
  {
    self::validateConditions($conditions, $source, 0);
  }

  /** Each outer any group starts a budget; nested members share that integer budget. */
  private static function validateConditions(array $conditions, string $source, int $depth, int &$remaining = PHP_INT_MAX): void
  {
    foreach ($conditions as $index => $condition) {
      $conditionSource = sprintf('%s[%d]', $source, $index);

      if ($remaining-- < 1) {
        throw new InvalidArgumentException(sprintf('%s exceeds the any condition tree member limit.', $conditionSource));
      }

      if (! is_array($condition)) {
        throw new InvalidArgumentException(sprintf('%s must be an array.', $conditionSource));
      }

      $typeValue = $condition['type'] ?? null;
      $type = is_string($typeValue) ? WorldConditionType::tryFrom(trim($typeValue)) : null;
      if (! $type instanceof WorldConditionType) {
        throw new InvalidArgumentException(sprintf(
          '%s field "type" has unsupported world condition "%s".',
          $conditionSource,
          is_scalar($typeValue) ? strval($typeValue) : get_debug_type($typeValue),
        ));
      }

      if ($type === WorldConditionType::ANY) {
        $groupBudget = self::MAX_ANY_TREE_MEMBERS;
        if ($depth === 0) {
          self::validateAny($condition, $conditionSource, $depth + 1, $groupBudget);
        } else {
          self::validateAny($condition, $conditionSource, $depth + 1, $remaining);
        }
        continue;
      }

      if (! is_string($condition['name'] ?? null) || trim($condition['name']) === '') {
        throw new InvalidArgumentException(sprintf('%s field "name" must be non-empty.', $conditionSource));
      }

      if (array_key_exists('negate', $condition) && ! is_bool($condition['negate'])) {
        throw new InvalidArgumentException(sprintf('%s field "negate" must be boolean.', $conditionSource));
      }

      if ($type === WorldConditionType::MAP_EVENT
        && (!is_string($condition['marker'] ?? null) || trim($condition['marker']) === '')) {
        throw new InvalidArgumentException(sprintf('%s map_event field "marker" must be non-empty.', $conditionSource));
      }

      if ($type === WorldConditionType::SWITCH && array_key_exists('value', $condition) && !is_bool($condition['value'])) {
        throw new InvalidArgumentException(sprintf('%s switch field "value" must be boolean.', $conditionSource));
      }

      if ($type === WorldConditionType::VARIABLE) {
        $operator = array_key_exists('op', $condition) ? $condition['op'] : '==';
        if (!is_string($operator) || ! in_array($operator, ['==', '!=', '>', '>=', '<', '<='], true)) {
          throw new InvalidArgumentException(sprintf(
            '%s field "op" has unsupported variable operator "%s".',
            $conditionSource,
            is_scalar($operator) ? strval($operator) : get_debug_type($operator),
          ));
        }
        if (array_key_exists('value', $condition) && !is_int($condition['value'])
          && !is_float($condition['value']) && !is_string($condition['value'])) {
          throw new InvalidArgumentException(sprintf('%s variable field "value" must be a number or string.', $conditionSource));
        }
      }

      if ($type === WorldConditionType::PLAY_STATISTIC && !self::isPlayStatisticConditionValid($condition)) {
        throw new InvalidArgumentException(sprintf('%s play_statistic requires a known counter, comparison operator and nonnegative integer value.',
          $conditionSource));
      }

      if ($type === WorldConditionType::ITEM
        && array_key_exists('quantity', $condition)
        && (! is_int($condition['quantity']) || $condition['quantity'] < 1)) {
        throw new InvalidArgumentException(sprintf('%s field "quantity" must be a positive integer.', $conditionSource));
      }

      if ($type === WorldConditionType::ASSIGNED_SUMMONS && !self::isAssignedSummonsConditionValid($condition)) {
        throw new InvalidArgumentException(sprintf('%s assigned_summons requires one of these names [%s] and a positive integer quantity.',
          $conditionSource, implode(', ', AssignedSummonScope::values())));
      }

      if ($type === WorldConditionType::PRESENT_PARTY_SIZE && !self::isPresentPartySizeConditionValid($condition)) {
        throw new InvalidArgumentException(sprintf('%s present_party_size requires one of these names [%s] and a positive integer quantity.',
          $conditionSource, implode(', ', PresentPartyScope::values())));
      }

      if ($type === WorldConditionType::QUEST && array_key_exists('status', $condition)
        && !in_array($condition['status'], ['active', 'completed'], true)) {
        throw new InvalidArgumentException(sprintf('%s quest field "status" must be active or completed.', $conditionSource));
      }
    }
  }

  private static function validateAny(array $condition, string $source, int $depth, int &$remaining): void
  {
    $members = $condition['conditions'] ?? null;
    if (array_diff(array_keys($condition), ['type', 'conditions', 'negate']) !== []
      || (array_key_exists('negate', $condition) && !is_bool($condition['negate']))
      || !is_array($members) || !array_is_list($members) || $members === []
      || count($members) > self::MAX_ANY_MEMBERS) {
      throw new InvalidArgumentException(sprintf('%s any condition requires 1 through %d conditions and an optional boolean negate.',
        $source, self::MAX_ANY_MEMBERS));
    }
    if ($depth > self::MAX_ANY_DEPTH) {
      throw new InvalidArgumentException(sprintf('%s exceeds the any condition nesting limit of %d.', $source, self::MAX_ANY_DEPTH));
    }
    self::validateConditions($members, "$source.conditions", $depth, $remaining);
  }

  /**
   * Yields leaf entries from a validated tree for reference and dependency checks.
   *
   * @param list<array<string, mixed>> $conditions
   * @return iterable<array<string, mixed>>
   */
  public static function getConditionLeaves(array $conditions): iterable
  {
    foreach ($conditions as $condition) {
      if (trim($condition['type']) === WorldConditionType::ANY->value) {
        yield from self::getConditionLeaves($condition['conditions']);
      } else {
        yield $condition;
      }
    }
  }

  /**
   * Determines whether every condition in the list holds.
   *
   * @param array<int, mixed> $conditions The condition entries.
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @param QuestLog|null $questLog Explicit state for detached/preflight evaluation; otherwise the running manager.
   * @return bool True when all conditions hold.
   */
  public static function allHold(array $conditions, GameState $gameState, ?Party $party = null, ?QuestLog $questLog = null,
    ?WorldAbilityCatalog $worldAbilities = null): bool
  {
    foreach ($conditions as $condition) {
      if (! is_array($condition)) {
        continue;
      }

      $typeValue = strval($condition['type'] ?? '');
      $type = WorldConditionType::tryFrom(trim($typeValue));

      if (! $type instanceof WorldConditionType) {
        Debug::warn(sprintf(
          'Unknown world condition type "%s" for "%s"; guarded content was not exposed.',
          $typeValue,
          strval($condition['name'] ?? '(unnamed)')
        ));

        return false;
      }

      if ($type === WorldConditionType::ANY) {
        try {
          $remaining = self::MAX_ANY_TREE_MEMBERS;
          self::validateAny($condition, 'world condition', 1, $remaining);
        } catch (InvalidArgumentException $error) {
          Debug::warn($error->getMessage());
          return false;
        }
        $matches = self::evaluate($type, $condition, '', $gameState, $party, $questLog, $worldAbilities);
        if ($matches === ($condition['negate'] ?? false)) { return false; }
        continue;
      }

      if (($type === WorldConditionType::PRESENT_ACTOR
          && (!is_string($condition['name'] ?? null) || trim($condition['name']) === ''))
        || ($type === WorldConditionType::PRESENT_PARTY_SIZE && !self::isPresentPartySizeConditionValid($condition))) {
        return false;
      }

      if ($type === WorldConditionType::PLAY_STATISTIC && !self::isPlayStatisticConditionValid($condition)) {
        return false;
      }

      $name = trim(strval($condition['name'] ?? ''));

      if ($type === WorldConditionType::MAP_EVENT
        && ($name === '' || !is_string($condition['marker'] ?? null) || trim($condition['marker']) === '')) {
        Debug::warn('A map_event condition requires a map name and event marker; guarded content was not exposed.');
        return false;
      }

      if ($type === WorldConditionType::ASSIGNED_SUMMONS && !self::isAssignedSummonsConditionValid($condition)) {
        return false;
      }

      if ($name === '') {
        continue;
      }

      $result = self::evaluate($type, $condition, $name, $gameState, $party, $questLog, $worldAbilities);

      if (($condition['negate'] ?? false) ? $result : ! $result) {
        return false;
      }
    }

    return true;
  }

  /** @param array<string, mixed> $condition */
  public static function isPlayStatisticConditionValid(array $condition): bool
  {
    return array_diff(array_keys($condition), ['type', 'name', 'op', 'value', 'negate']) === []
      && is_string($condition['name'] ?? null) && PlayStatistic::tryFrom(trim($condition['name'])) !== null
      && in_array($condition['op'] ?? '==', ['==', '!=', '>', '>=', '<', '<='], true)
      && is_int($condition['value'] ?? null) && $condition['value'] >= 0
      && (!array_key_exists('negate', $condition) || is_bool($condition['negate']));
  }

  /** @param array<string, mixed> $condition */
  public static function isAssignedSummonsConditionValid(array $condition): bool
  {
    return is_string($condition['name'] ?? null) && AssignedSummonScope::tryFrom(trim($condition['name'])) !== null
      && is_int($condition['quantity'] ?? null) && $condition['quantity'] > 0;
  }

  /** @param array<string, mixed> $condition */
  public static function isPresentPartySizeConditionValid(array $condition): bool
  {
    return is_string($condition['name'] ?? null) && PresentPartyScope::tryFrom(trim($condition['name'])) !== null
      && is_int($condition['quantity'] ?? null) && $condition['quantity'] > 0;
  }

  /**
   * Evaluates a single condition, ignoring its `negate` flag.
   *
   * @param WorldConditionType $type The recognized condition type.
   * @param array<string, mixed> $condition The condition entry.
   * @param string $name The trimmed condition name.
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @param QuestLog|null $questLog Explicit quest state when supplied.
   * @return bool True when the condition's test passes.
   */
  protected static function evaluate(
    WorldConditionType $type,
    array $condition,
    string $name,
    GameState $gameState,
    ?Party $party,
    ?QuestLog $questLog = null,
    ?WorldAbilityCatalog $worldAbilities = null,
  ): bool
  {
    return match ($type) {
      WorldConditionType::ANY => array_any($condition['conditions'], static function (array $member) use ($gameState, $party, $questLog, $worldAbilities): bool {
        $memberType = WorldConditionType::from(trim($member['type']));
        $matches = self::evaluate($memberType, $member, trim($member['name'] ?? ''), $gameState, $party, $questLog, $worldAbilities);
        return $matches !== ($member['negate'] ?? false);
      }),
      WorldConditionType::WORLD_ABILITY => ($worldAbilities ?? WorldAbilityCatalog::getProjectCatalog())
        ->isUnlocked($name, $gameState, $party, $questLog),
      WorldConditionType::SWITCH => $gameState->getSwitch($name) === (bool) ($condition['value'] ?? true),
      WorldConditionType::EVENT => $gameState->hasStoryEvent($name),
      WorldConditionType::MAP_EVENT => is_string($condition['marker'] ?? null)
        && trim($condition['marker']) !== '' && $gameState->isEventComplete($name, trim($condition['marker'])),
      WorldConditionType::VARIABLE => self::compare(
        $gameState->getVariable($name),
        strval($condition['op'] ?? '=='),
        $condition['value'] ?? 0
      ),
      WorldConditionType::PLAY_STATISTIC => self::compare(
        $gameState->getPlayStatistic(PlayStatistic::from($name)), $condition['op'] ?? '==', $condition['value']),
      WorldConditionType::ITEM => ($party?->inventory?->getQuantity($name, 'evaluating an item world condition') ?? 0) >= max(1, intval($condition['quantity'] ?? 1)),
      WorldConditionType::ASSIGNED_SUMMONS => match (AssignedSummonScope::from($name)) {
        AssignedSummonScope::PARTY => count($party?->getAssignedSummonIds() ?? []) >= $condition['quantity'],
      },
      WorldConditionType::PRESENT_ACTOR => array_any($party?->participants->toArray() ?? [],
        static fn($member): bool => $member instanceof Character && $member->actorId === $name),
      WorldConditionType::PRESENT_PARTY_SIZE => match (PresentPartyScope::from($name)) {
        PresentPartyScope::PARTY => $party?->participants->count() === $condition['quantity'],
      },
      WorldConditionType::KEY_ITEM => $party?->inventory?->hasKeyItem($name) ?? false,
      WorldConditionType::QUEST => $questLog !== null
        ? (($condition['status'] ?? 'completed') === 'active' ? $questLog->isActive($name) : $questLog->isCompleted($name))
        : (QuestManager::current()?->questStatusMatches($name, strval($condition['status'] ?? 'completed')) ?? false),
    };
  }

  /**
   * Canonicalizes numeric operands with the same equality results for world variables.
   * Keep large floats and their strings distinct, where integer rounding can change equality.
   */
  public static function normalizeEqualityOperand(int|float|string|bool $value): int|float|string|bool
  {
    $number = is_string($value) && is_numeric($value) ? $value + 0 : $value;
    if (is_float($number) && abs($number) < 9_007_199_254_740_992.0) {
      return floor($number) === $number ? (int)$number : $number;
    }
    return is_int($number) ? $number : $value;
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
