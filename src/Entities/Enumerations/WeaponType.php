<?php

namespace Ichiloto\Engine\Entities\Enumerations;

use Ichiloto\Engine\Entities\Interfaces\InventoryTypeInterface;
use InvalidArgumentException;

/**
 * The WeaponType class.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum WeaponType: string implements InventoryTypeInterface
{
  case DAGGER = 'Dagger';
  case SWORD = 'Sword';
  case FLAIL = 'Flail';
  case AXE = 'Axe';
  case WHIP = 'Whip';
  case STAFF = 'Staff';
  case BOW = 'Bow';
  case CROSSBOW = 'Crossbow';
  case GUN = 'Gun';
  case CLAW = 'Claw';
  case GLOVE = 'Glove';
  case SPEAR = 'Spear';
  case WAND = 'Wand';

  public static function require(mixed $value): self
  {
    if ($value instanceof self) { return $value; }
    if (is_string($value)) {
      foreach (self::cases() as $type) {
        if (strcasecmp(trim($value), $type->value) === 0) { return $type; }
      }
    }
    throw new InvalidArgumentException('Weapon type must be a supported WeaponType value.');
  }
}
