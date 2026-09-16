<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats\StatKey;

/** Immutable facts at an award boundary, never a reference to mutable character state. */
final readonly class ProgressionSnapshot
{
  /** @param array<int, int> $thresholds @param array<string, int> $stats */
  public function __construct(
    public string $actorId,
    public string $name,
    public int $level,
    public int $experience,
    public int $maxLevel,
    public array $thresholds,
    public array $stats,
  ) {}

  public static function capture(Character $character): self
  {
    $stats = [];
    $effective = $character->effectiveStats;
    foreach (StatKey::cases() as $key) {
      $stats[$key->value] = $effective->{$key->statsProperty()};
    }
    return new self($character->actorId, $character->name, $character->level,
      $character->currentExp, $character->maxLevel, $character->getLevelExperienceThresholds(), $stats);
  }

  /** @return array{level: int, current: int, needed: int, ratio: float, maximum: bool} */
  public function progressAt(int $experience): array
  {
    $level = 1;
    foreach ($this->thresholds as $candidate => $threshold) {
      if ($experience < $threshold || $candidate > $this->maxLevel) { break; }
      $level = max(1, $candidate);
    }
    $maximum = $level >= $this->maxLevel;
    $base = $this->thresholds[$level] ?? 0;
    $needed = $maximum ? 0 : max(1, ($this->thresholds[$level + 1] ?? $base + 1) - $base);
    $current = $maximum ? 0 : max(0, $experience - $base);
    return ['level' => $level, 'current' => $current, 'needed' => $needed,
      'ratio' => $maximum ? 1.0 : min(1.0, $current / $needed), 'maximum' => $maximum];
  }
}
