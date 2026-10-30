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
    public ?BattleGroundScale $scaleReference = null,
  ) {
    new BattleGround($top, $bottom, $perspective);
    $this->depth = $bottom - $top;
    if ($scaleReference !== null && ($top <= $scaleReference->horizonY
      || max($scaleReference->getDepthScale($top), $scaleReference->getDepthScale($bottom)) > 64)) {
      throw new InvalidArgumentException('The calibrated standing band must lie below the horizon with display scale within 0..64.');
    }
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
    if (!is_finite($footY)) { throw new InvalidArgumentException('A ground row must be finite.'); }
    if ($this->scaleReference !== null) {
      return $this->scaleReference->getDepthScale(max($this->top, min($this->bottom, $footY)));
    }
    $farness = max(0, min(1, ($this->bottom - $footY) / $this->depth));
    return 1 + ($this->perspective - 1) * $farness;
  }

  /** @return array{slope: float, intercept: float} The same linear scale used by body-clearance constraints. */
  public function getDepthScaleLine(): array
  {
    if ($this->scaleReference !== null) { return $this->scaleReference->getDepthScaleLine(); }
    $slope = (1 - $this->perspective) / $this->depth;
    return ['slope' => $slope, 'intercept' => 1 - $slope * $this->bottom];
  }

  /** @return array{top: float, bottom: float, perspective?: float, scaleReference?: array} */
  public function toArray(): array
  {
    return ['top' => $this->top, 'bottom' => $this->bottom,
      ...($this->scaleReference === null ? ['perspective' => $this->perspective] : ['scaleReference' => $this->scaleReference->toArray()])];
  }
}
