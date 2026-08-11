<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

/**
 * Represents the outcome of a battle.
 *
 * @package Ichiloto\Engine\Battle
 */
class BattleResult
{
  /**
   * Creates a new battle result instance.
   *
   * @param string $title The result title.
   * @param string[] $lines The result summary lines.
   * @param InventoryItem[] $items The earned items.
   * @param array<int, array{label: string, value: string}> $entries The staged reward entries.
   */
  public function __construct(
    protected(set) string $title,
    protected(set) array $lines = [],
    protected(set) array $items = [],
    protected(set) array $entries = [],
  )
  {
  }

  /**
   * Returns the stable script-facing outcome represented by this result.
   *
   * Existing battle states already use result titles such as Victory and
   * Defeat. Event scripts consume a normalized value without introducing a
   * second battle-result model.
   */
  public function outcome(): string
  {
    $title = strtolower(trim($this->title));

    return match (true) {
      str_starts_with($title, 'victory') => 'victory',
      str_starts_with($title, 'defeat') => 'defeat',
      str_starts_with($title, 'escape'), str_starts_with($title, 'retreat') => 'escape',
      default => $title !== '' ? preg_replace('/[^a-z0-9]+/', '_', $title) ?: 'unknown' : 'unknown',
    };
  }
}
