<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Quests\QuestManager;
use InvalidArgumentException;

/**
 * Applies authored world-state writes.
 *
 * The counterpart to {@see WorldConditionEvaluator}: one implementation of
 * the `sets` vocabulary shared by every surface that can change the world —
 * event triggers on completion, NPCs after a conversation, and conditional
 * dialogue variants when spoken.
 *
 * Supported shapes:
 * - `['type' => 'switch',   'name' => 'gate_open', 'value' => true]`
 * - `['type' => 'event',    'name' => 'met_the_king']`
 * - `['type' => 'variable', 'name' => 'donations', 'op' => 'set'|'add', 'value' => 1]`
 * - `['type' => 'quest',    'name' => 'quest-id', 'confirm' => true]` accepts
 *   the quest. An optional quest asks the player first unless
 *   `'confirm' => false` grants it outright.
 *
 * @package Ichiloto\Engine\Core
 */
final class WorldStateWriter
{
  /**
   * WorldStateWriter constructor.
   */
  private function __construct()
  {
  }

  /**
   * Applies every write in the list.
   *
   * @param array<int, mixed> $sets The authored write entries.
   * @param GameState $gameState The world state to write to.
   * @return void
   */
  public static function applyAll(array $sets, GameState $gameState): void
  {
    foreach ($sets as $set) {
      if (! is_array($set)) {
        continue;
      }

      $name = trim(strval($set['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      match (strval($set['type'] ?? '')) {
        'switch' => $gameState->setSwitch($name, (bool) ($set['value'] ?? true)),
        'event' => $gameState->recordStoryEvent($name),
        'variable' => strval($set['op'] ?? 'set') === 'add'
          ? $gameState->addToVariable($name, is_numeric($set['value'] ?? 1) ? $set['value'] + 0 : 1)
          : $gameState->setVariable($name, $set['value'] ?? 0),
        'quest' => QuestManager::current()?->acceptQuest($name, ($set['confirm'] ?? true) !== false),
        default => null,
      };
    }
  }

  /**
   * Validates writes for a fail-closed authoring boundary.
   *
   * Transactional callers deliberately reject quest acceptance because the
   * current quest UI/session has external side effects that cannot be rolled
   * back with the GameState store. Existing non-transactional callers retain
   * the complete historical vocabulary.
   *
   * @param array<int, mixed> $sets
   */
  public static function validateAll(
    array $sets,
    string $source = 'world-state writes',
    bool $transactional = false,
  ): void
  {
    foreach ($sets as $index => $set) {
      $writeSource = sprintf('%s[%d]', $source, $index);

      if (! is_array($set)) {
        throw new InvalidArgumentException(sprintf('%s must be an array.', $writeSource));
      }

      $type = $set['type'] ?? null;
      if (! is_string($type) || ! in_array($type, ['switch', 'event', 'variable', 'quest'], true)) {
        throw new InvalidArgumentException(sprintf(
          '%s field "type" has unsupported world write "%s".',
          $writeSource,
          is_scalar($type) ? strval($type) : get_debug_type($type),
        ));
      }

      if (! is_string($set['name'] ?? null) || trim($set['name']) === '') {
        throw new InvalidArgumentException(sprintf('%s field "name" must be non-empty.', $writeSource));
      }

      if ($transactional && $type === 'quest') {
        throw new InvalidArgumentException(sprintf(
          '%s field "type" cannot use quest acceptance in an atomic transaction; write a reversible switch, event, or variable instead.',
          $writeSource,
        ));
      }

      if ($type === 'switch' && isset($set['value']) && ! is_bool($set['value'])) {
        throw new InvalidArgumentException(sprintf('%s field "value" must be boolean for a switch write.', $writeSource));
      }

      if ($type === 'variable') {
        $operation = $set['op'] ?? 'set';
        if (! is_string($operation) || ! in_array($operation, ['set', 'add'], true)) {
          throw new InvalidArgumentException(sprintf('%s field "op" must be "set" or "add".', $writeSource));
        }

        $value = $set['value'] ?? ($operation === 'add' ? 1 : 0);
        if ($operation === 'add' && ! is_int($value) && ! is_float($value)) {
          throw new InvalidArgumentException(sprintf('%s field "value" must be numeric for an add operation.', $writeSource));
        }
        if ($operation === 'set' && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
          throw new InvalidArgumentException(sprintf('%s field "value" must be an integer, float, or string.', $writeSource));
        }
      }
    }
  }

  /**
   * Applies previously validated writes and throws rather than skipping data.
   *
   * @param array<int, mixed> $sets
   */
  public static function applyAllStrict(array $sets, GameState $gameState, string $source = 'world-state writes'): void
  {
    self::validateAll($sets, $source, transactional: true);

    foreach ($sets as $set) {
      $name = trim($set['name']);

      match ($set['type']) {
        'switch' => $gameState->setSwitch($name, $set['value'] ?? true),
        'event' => $gameState->recordStoryEvent($name),
        'variable' => ($set['op'] ?? 'set') === 'add'
          ? $gameState->addToVariable($name, $set['value'] ?? 1)
          : $gameState->setVariable($name, $set['value'] ?? 0),
      };
    }
  }
}
