<?php

namespace Ichiloto\Engine\Field\Enumerations;

/**
 * Which way one place lies from another.
 *
 * @package Ichiloto\Engine\Field\Enumerations
 */
enum CompassDirection: string
{
  case NORTH = 'north';
  case NORTH_EAST = 'north-east';
  case EAST = 'east';
  case SOUTH_EAST = 'south-east';
  case SOUTH = 'south';
  case SOUTH_WEST = 'south-west';
  case WEST = 'west';
  case NORTH_WEST = 'north-west';

  /**
   * Returns the direction of a door, from where it sits on its map.
   *
   * A door's position is where the place it leads to lies: the townsfolk put
   * the shop's door on the south side of the square because the shop is south.
   * Positions near the middle on an axis do not count towards a direction, so
   * a door on the west wall reads as west rather than north-west.
   *
   * @param float $x The door's x position, from 0.0 to 1.0 across the map.
   * @param float $y The door's y position, from 0.0 to 1.0 down the map.
   * @param float $deadZone How close to the middle still counts as centred.
   * @return self|null The direction, or null for a door in the middle.
   */
  public static function fromPosition(float $x, float $y, float $deadZone = 0.15): ?self
  {
    $horizontal = match (true) {
      $x < 0.5 - $deadZone => 'west',
      $x > 0.5 + $deadZone => 'east',
      default => '',
    };
    $vertical = match (true) {
      $y < 0.5 - $deadZone => 'north',
      $y > 0.5 + $deadZone => 'south',
      default => '',
    };

    return self::tryFrom(trim("{$vertical}-{$horizontal}", '-'));
  }

  /**
   * Returns the step this direction takes on a grid.
   *
   * @return array{0: int, 1: int} The x and y steps.
   */
  public function offset(): array
  {
    return match ($this) {
      self::NORTH => [0, -1],
      self::NORTH_EAST => [1, -1],
      self::EAST => [1, 0],
      self::SOUTH_EAST => [1, 1],
      self::SOUTH => [0, 1],
      self::SOUTH_WEST => [-1, 1],
      self::WEST => [-1, 0],
      self::NORTH_WEST => [-1, -1],
    };
  }

  /**
   * Returns the direction facing the other way.
   *
   * @return self The opposite direction.
   */
  public function opposite(): self
  {
    [$x, $y] = $this->offset();

    return self::fromOffset(-$x, -$y) ?? $this;
  }

  /**
   * Returns the direction a grid step points in.
   *
   * @param int $x The x step.
   * @param int $y The y step.
   * @return self|null The direction, or null when the step is nowhere.
   */
  public static function fromOffset(int $x, int $y): ?self
  {
    foreach (self::cases() as $direction) {
      if ($direction->offset() === [$x <=> 0, $y <=> 0]) {
        return $direction;
      }
    }

    return null;
  }
}
