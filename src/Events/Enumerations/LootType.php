<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * The LootType enum.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum LootType: string
{
  case ITEM = 'item';
  case GOLD = 'gold';
  case EXPERIENCE = 'experience';
  case SKILL = 'skill';
  case SPELL = 'spell';
  case WEAPON = 'weapon';
  case ARMOR = 'armor';
  case ACCESSORY = 'accessory';
}
