<?php

namespace Ichiloto\Engine\Physics;

use Ichiloto\Engine\Core\Game;

/**
 * Class PhysicsManager. Manages the physics of the game.
 *
 * @package Ichiloto\Engine\Physics
 */
class PhysicsManager
{
  public static ?PhysicsManager $instance = null;

  private function __construct(protected Game $game)
  {
  }

  public static function getInstance(Game $game): self
  {
    if (!self::$instance) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }
}