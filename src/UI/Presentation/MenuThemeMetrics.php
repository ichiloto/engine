<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** Authored typography and spacing, not source image dimensions or gameplay state. */
final readonly class MenuThemeMetrics
{
  public function __construct(
    public int $cellWidth = 10,
    public int $cellHeight = 24,
    public int $rowHeight = 40,
    public int $panelPadding = 20,
    public int $sectionGap = 12,
    public int $portraitSize = 112,
  ) {
    if ($cellWidth < 4 || $cellWidth > 32 || $cellHeight < 8 || $cellHeight > 64
      || $rowHeight < $cellHeight + 1 || $rowHeight > 128 || $panelPadding < 0 || $panelPadding > 64
      || $sectionGap < 0 || $sectionGap > 64 || $portraitSize < 16 || $portraitSize > 192) {
      throw new InvalidArgumentException('Menu typography and spacing must fit the supported presentation bounds.');
    }
  }
}
