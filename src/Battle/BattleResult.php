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
   */
  public function __construct(
    protected(set) string $title,
    protected(set) array $lines = [],
    protected(set) array $items = [],
  )
  {
  }
}
