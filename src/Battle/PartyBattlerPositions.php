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
  public static function createForSize(int $size, int $width = 135, int $height = 30): self
  {
    if ($size < 1 || $size > \Ichiloto\Engine\Entities\Party::MAX_BATTLE_SIZE) {
      throw new \InvalidArgumentException('Terminal battle formations support 1 to 8 members.');
    }
    if ($size <= 3) { return new self(); }
    $rows = (int) ceil($size / 2);
    $idle = $active = [];
    for ($index = 0; $index < $size; $index++) {
      $column = intdiv($index, $rows);
      $x = $width - 26 - $column * 20;
      $y = 2 + ($index % $rows) * max(1, intdiv($height - 4, $rows));
      $idle[] = new Vector2($x, $y);
      $active[] = new Vector2($x - 6, $y);
    }
    return new self($idle, $active);
  }

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
      new Vector2(103, 5),
      new Vector2(99, 13),
      new Vector2(103, 21),
    ]
  )
  {
  }
}
