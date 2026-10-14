<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Theme geometry for static badges, independent of effect and gameplay timing. */
final class BattleConditionBadgeStyle
{
  public int $size { get => 16 * $this->pixelSize; }
  public int $markerPixelSize { get => max(1, intdiv($this->pixelSize, 2)); }
  public int $labelCellWidth { get => max(6, 2 * $this->pixelSize); }
  public int $labelCellHeight { get => max(12, 4 * $this->pixelSize); }
  public int $height { get => $this->size + $this->labelCellHeight; }

  public function __construct(public readonly int $pixelSize = 2, public readonly int $gap = 4,
    public readonly int $maxColumns = 3)
  {
    if ($pixelSize < 2 || $pixelSize > 8 || $gap < 0 || $gap > 32 || $maxColumns < 1 || $maxColumns > 8) {
      throw new InvalidArgumentException('Condition badges require pixel size 2..8, gap 0..32 and columns 1..8.');
    }
  }
}
