<?php

namespace Ichiloto\Engine\Entities\Stats;

use InvalidArgumentException;

/** Entity-category caps. Player and enemy vitality are deliberately separate. */
final readonly class EntityStatCapPolicy
{
  /** @param array<string, int> $caps */
  public function __construct(private array $caps)
  {
    foreach (StatKey::cases() as $stat) {
      $cap = $this->caps[$stat->value] ?? null;

      if (! is_int($cap) || $cap < 1) {
        throw new InvalidArgumentException(sprintf('Missing or invalid cap for %s.', $stat->value));
      }
    }
  }

  public static function player(): self
  {
    static $policy = null;

    return $policy ??= new self([
      StatKey::MAX_HP->value => 9_999,
      StatKey::MAX_MP->value => 999,
      StatKey::ATTACK->value => 999,
      StatKey::DEFENCE->value => 999,
      StatKey::MAGIC_ATTACK->value => 999,
      StatKey::MAGIC_DEFENCE->value => 999,
      StatKey::SPEED->value => 999,
      StatKey::GRACE->value => 999,
      StatKey::EVASION->value => 999,
    ]);
  }

  public static function enemy(): self
  {
    static $policy = null;

    return $policy ??= new self([
      StatKey::MAX_HP->value => 999_999,
      StatKey::MAX_MP->value => 99_999,
      StatKey::ATTACK->value => 9_999,
      StatKey::DEFENCE->value => 9_999,
      StatKey::MAGIC_ATTACK->value => 9_999,
      StatKey::MAGIC_DEFENCE->value => 9_999,
      StatKey::SPEED->value => 9_999,
      StatKey::GRACE->value => 9_999,
      StatKey::EVASION->value => 9_999,
    ]);
  }

  public function capFor(StatKey $stat): int
  {
    return $this->caps[$stat->value];
  }
}
