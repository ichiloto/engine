<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** How one authored magnitude changes HP. */
enum ResolutionKind: string
{
  case PHYSICAL_DAMAGE = 'physicalDamage';
  case MAGICAL_DAMAGE = 'magicalDamage';
  case TRUE_DAMAGE = 'trueDamage';
  case HEALING = 'healing';

  public function isDamage(): bool
  {
    return $this !== self::HEALING;
  }
}
