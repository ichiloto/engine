<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;

/**
 * Chooses what a speaker says based on the state of the world.
 *
 * Dialogue is authored either as a plain list of pages (one thing to say,
 * always) or as an ordered list of variants where the **first** whose
 * conditions hold is spoken:
 *
 * ```php
 * 'dialogue' => [
 *   [
 *     'conditions' => [['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed']],
 *     'lines' => [['name' => 'Mom', 'text' => 'You found one! Bless you.']],
 *   ],
 *   [
 *     'conditions' => [['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active']],
 *     'lines' => [['name' => 'Mom', 'text' => 'Still no S-Mana? The shop is in the town centre.']],
 *   ],
 *   [
 *     'lines' => [['name' => 'Mom', 'text' => 'Good morning, dear.']],   // fallback
 *   ],
 * ],
 * ```
 *
 * Order matters: put the most advanced state first, and leave a variant
 * with no conditions last as the fallback. A variant may also carry `sets`
 * (world-state writes applied when it is spoken) and a `script` of event
 * commands instead of, or in addition to, its lines.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
 */
final class ConditionalDialogue
{
  /**
   * ConditionalDialogue constructor.
   */
  private function __construct()
  {
  }

  /**
   * Determines whether the authored dialogue uses conditional variants.
   *
   * A variant is recognised by carrying `lines`, `script`, or `conditions`;
   * anything else is treated as a plain page list, so existing content keeps
   * working untouched.
   *
   * @param array<int, mixed> $dialogue The authored dialogue entry.
   * @return bool True when the entry is a list of variants.
   */
  public static function isVariantList(array $dialogue): bool
  {
    foreach ($dialogue as $entry) {
      if (! is_array($entry)) {
        continue;
      }

      if (isset($entry['lines']) || isset($entry['script']) || isset($entry['conditions'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Selects the variant to speak.
   *
   * @param array<int, mixed> $dialogue The authored dialogue entry.
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @return array{lines: array<int, array<string, mixed>>, script: array<int, array<string, mixed>>, sets: array<int, array<string, mixed>>} The chosen content.
   */
  public static function select(array $dialogue, GameState $gameState, ?Party $party = null): array
  {
    if (! self::isVariantList($dialogue)) {
      // A plain page list is a single unconditional variant.
      return [
        'lines' => array_values(array_filter($dialogue, 'is_array')),
        'script' => [],
        'sets' => [],
      ];
    }

    foreach ($dialogue as $variant) {
      if (! is_array($variant)) {
        continue;
      }

      $conditions = array_values(array_filter((array) ($variant['conditions'] ?? []), 'is_array'));

      if (! WorldConditionEvaluator::allHold($conditions, $gameState, $party)) {
        continue;
      }

      return [
        'lines' => array_values(array_filter((array) ($variant['lines'] ?? []), 'is_array')),
        'script' => array_values(array_filter((array) ($variant['script'] ?? []), 'is_array')),
        'sets' => array_values(array_filter((array) ($variant['sets'] ?? []), 'is_array')),
      ];
    }

    // Every variant was gated out: the speaker has nothing to say right now.
    return ['lines' => [], 'script' => [], 'sets' => []];
  }
}
