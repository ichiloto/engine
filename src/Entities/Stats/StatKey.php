<?php

namespace Ichiloto\Engine\Entities\Stats;

use InvalidArgumentException;

/** Canonical stat vocabulary shared by progression, equipment and combat. */
enum StatKey: string
{
  case MAX_HP = 'maxHp';
  case MAX_MP = 'maxMp';
  case ATTACK = 'attack';
  case DEFENCE = 'defence';
  case MAGIC_ATTACK = 'magicAttack';
  case MAGIC_DEFENCE = 'magicDefence';
  case SPEED = 'speed';
  case GRACE = 'grace';
  case EVASION = 'evasion';

  public static function require(string $value): self
  {
    return self::tryFrom(trim($value))
      ?? throw new InvalidArgumentException(sprintf('Unknown stat key: %s.', $value));
  }
}
