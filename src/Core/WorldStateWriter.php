<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Quests\QuestManager;

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
}
