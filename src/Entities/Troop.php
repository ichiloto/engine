<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\GroupInterface;

/**
 * Represents a group of enemies in a battle.
 *
 * @package Ichiloto\Engine\Entities
 * @extends BattleGroup<Enemy>
 */
class Troop extends BattleGroup
{
  /**
   * @inheritDoc
   */
  public function configure(array $config = []): void
  {
    // Do nothing
  }
}