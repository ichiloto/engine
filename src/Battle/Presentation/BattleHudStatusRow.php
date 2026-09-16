<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Copied effective vitals; no reference to mutable battlers or their stats. */
final readonly class BattleHudStatusRow
{
  public float $hpRatio;
  public float $mpRatio;
  public ?float $atbRatio;

  public function __construct(
    public int $index,
    public int $currentHp,
    public int $totalHp,
    public int $currentMp,
    public int $totalMp,
    ?float $atbRatio = null,
  ) {
    if ($atbRatio !== null && !is_finite($atbRatio)) {
      throw new InvalidArgumentException('Battle HUD ATB ratios must be finite.');
    }
    $this->hpRatio = clamp($currentHp / max(1, $totalHp), 0.0, 1.0);
    $this->mpRatio = clamp($currentMp / max(1, $totalMp), 0.0, 1.0);
    $this->atbRatio = $atbRatio === null ? null : clamp($atbRatio, 0.0, 1.0);
  }
}
