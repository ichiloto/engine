<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Vector2;

/**
 * Location class. Represents a location in the game.
 *
 * @package Ichiloto\Engine\Field
 */
readonly class Location
{
  /**
   * Creates a new location.
   *
   * @param string $mapFilename The map filename.
   * @param Vector2 $playerPosition The player position.
   * @param array|null $playerSprite The player sprite.
   */
  public function __construct(
    public string $mapFilename,
    public Vector2 $playerPosition,
    public ?array $playerSprite
  )
  {
  }
}