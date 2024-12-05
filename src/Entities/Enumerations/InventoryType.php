<?php

namespace Ichiloto\Engine\Entities\Enumerations;

use Ichiloto\Engine\Entities\Interfaces\InventoryTypeInterface;

/**
 * The WeaponType class.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum InventoryType: string implements InventoryTypeInterface
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
}
