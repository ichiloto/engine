<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;

/**
 * What is drawn where a battler meets the surface: a shadow on the ground
 * under it, a ripple in water around a body rising out of it, a burrow in the
 * ground around a body poking out of it, or nothing.
 */
enum BattlerContact: string
{
  case SHADOW = 'shadow';
  case RIPPLE = 'ripple';
  case BURROW = 'burrow';
  case NONE = 'none';

  /**
   * What a battler meets the surface with unless it says: a body sunk below
   * the surface (negative elevation) ripples in water and burrows on land;
   * everything else casts a shadow.
   */
  public static function getDefault(?EnemyHabitat $habitat, float $elevation): self
  {
    if ($elevation >= 0) { return self::SHADOW; }
    return match ($habitat) {
      EnemyHabitat::WATER => self::RIPPLE,
      EnemyHabitat::AIR => self::SHADOW,
      default => self::BURROW,
    };
  }
}
