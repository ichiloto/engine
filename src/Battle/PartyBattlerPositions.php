<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Core\Vector2;

/**
 * Represents the party battler positions.
 *
 * @package Ichiloto\Engine\Battle
 */
readonly class PartyBattlerPositions
{
  /**
   * Creates a new instance of the party battler positions.
   *
   * @param Vector2[] $idlePositions The idle positions.
   * @param Vector2[] $activePositions The active positions.
   */
  public function __construct(
    public array $idlePositions = [
      new Vector2(109, 5),
      new Vector2(105, 13),
      new Vector2(109, 21),
    ],
    public array $activePositions = [
      new Vector2(109, 5),
      new Vector2(105, 13),
      new Vector2(109, 21),
    ]
  )
  {
  }
}