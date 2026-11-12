<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Enumerations;

/**
 * Who moved the player one cell: the player, or a script that owns the movement.
 *
 * Both reach the destination the same way and run its arrival triggers. Only a
 * player-controlled step advances step-driven systems: random encounters,
 * walking conditions and anything else that counts the player's walking.
 *
 * @package Ichiloto\Engine\Field\Enumerations
 */
enum StepOrigin
{
  /** Movement input from the player on the field. */
  case PLAYER_INPUT;
  /** A move route, cinematic or other event-owned movement. */
  case SCRIPTED;
}
