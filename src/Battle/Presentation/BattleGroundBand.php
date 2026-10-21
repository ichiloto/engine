<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** A battle's resolved ground: every edge known, in canvas units ({@see BattleGround}). */
final readonly class BattleGroundBand
{
  public float $depth;

  public function __construct(
    public float $top,
    public float $bottom,
    public float $perspective = 1.0,
  ) {
    new BattleGround($top, $bottom, $perspective);
    $this->depth = $bottom - $top;
  }

  /** Where feet stand that far back from the near edge, never beyond the far edge. */
  public function getFootY(float $depth): float
  {
    if (!is_finite($depth) || $depth < 0) {
      throw new InvalidArgumentException('Stage depth must be finite and not negative.');
    }
    return $this->bottom - min($depth, $this->depth);
  }

  /** The perspective display scale of feet standing at a canvas height on this ground. */
  public function getDepthScale(float $footY): float
  {
    $farness = max(0, min(1, ($this->bottom - $footY) / $this->depth));
    return 1 + ($this->perspective - 1) * $farness;
  }

  /** @return array{top: float, bottom: float, perspective: float} */
  public function toArray(): array
  {
    return ['top' => $this->top, 'bottom' => $this->bottom, 'perspective' => $this->perspective];
  }
}
