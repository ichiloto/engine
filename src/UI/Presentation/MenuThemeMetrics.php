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
    public int $sliderTrackHeight = 8,
    public int $sliderFillHeight = 2,
    public int $sliderThumbSize = 20,
    public int $scrollbarWidth = 24,
    public int $scrollbarTrackWidth = 12,
    public int $scrollbarMinThumbHeight = 28,
    public int $scrollbarArrowGap = 4,
  ) {
    if ($cellWidth < 4 || $cellWidth > 32 || $cellHeight < 8 || $cellHeight > 64
      || $rowHeight < $cellHeight + 1 || $rowHeight > 128 || $panelPadding < 0 || $panelPadding > 64
      || $sectionGap < 0 || $sectionGap > 64 || $portraitSize < 16 || $portraitSize > 192) {
      throw new InvalidArgumentException('Menu typography and spacing must fit the supported presentation bounds.');
    }
    if ($sliderFillHeight < 1 || $sliderFillHeight > $sliderTrackHeight
      || $sliderTrackHeight > $sliderThumbSize || $sliderThumbSize > 64) {
      throw new InvalidArgumentException('Slider metrics require 1 <= fill height <= track height <= thumb size <= 64.');
    }
    if ($scrollbarTrackWidth < 1 || $scrollbarTrackWidth > $scrollbarWidth || $scrollbarWidth > 64
      || $scrollbarMinThumbHeight < 1 || $scrollbarMinThumbHeight > 128
      || $scrollbarArrowGap < 0 || $scrollbarArrowGap > 32) {
      throw new InvalidArgumentException('Scrollbar metrics require a bounded rail, track, minimum thumb and arrow gap.');
    }
  }
}
