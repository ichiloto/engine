<?php

namespace Ichiloto\Engine\Entities\Inventory;

use InvalidArgumentException;

/** Semantic equipment slots, independent of broad PHP runtime classes. */
enum EquipmentSlotType: string
{
  case WEAPON = 'weapon';
  case SHIELD = 'shield';
  case HEAD = 'head';
  case BODY = 'body';
  case ACCESSORY = 'accessory';

  public static function require(self|string $value): self
  {
    if ($value instanceof self) {
      return $value;
    }

    return self::tryFrom(strtolower(trim($value)))
      ?? throw new InvalidArgumentException(sprintf('Unknown equipment slot: %s.', $value));
  }
}
