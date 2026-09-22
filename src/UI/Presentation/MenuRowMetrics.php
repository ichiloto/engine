<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** Authored logical spacing and motion, never image-source dimensions. */
final readonly class MenuRowMetrics
{
  public function __construct(
    public int $padding = 30,
    public int $gapCells = 1,
    public float $iconWidth = 24,
    public float $iconHeight = 24,
    public float $cursorWidth = 16,
    public float $cursorHeight = 16,
    public float $cursorInset = 8,
    public float $cursorTravel = 4,
    public float $cursorPeriod = 1.2,
    public float $separatorWidth = 1,
    public float $recordSeparatorOpacity = 64 / 255,
    public float $headingSeparatorOpacity = 1,
    public float $accentWidth = 2,
    public float $focusWidth = 1,
  ) {
    foreach (get_object_vars($this) as $value) {
      if (!is_finite($value) || $value < 0) { throw new InvalidArgumentException('Menu metrics must be finite and nonnegative.'); }
    }
    if (min($iconWidth, $iconHeight, $cursorWidth, $cursorHeight, $cursorPeriod) <= 0
      || max($recordSeparatorOpacity, $headingSeparatorOpacity) > 1) {
      throw new InvalidArgumentException('Menu image boxes and cursor period must be positive; opacity must be in 0..1.');
    }
  }

  public function cursorOffset(float $time, bool $reducedMotion): float
  {
    return $reducedMotion ? 0.0 : $this->cursorTravel / 2 * (1 - cos(2 * M_PI * fmod($time, $this->cursorPeriod) / $this->cursorPeriod));
  }
}
