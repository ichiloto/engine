<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Windows;

/** The shared Terminal Save/Load page geometry; selection remains scene-owned. */
final readonly class SaveSlotMenuLayout
{
  public int $width;
  public int $infoHeight;
  public int $helpHeight;
  public int $visibleCount;
  public int $left;
  public int $top;

  public function __construct(
    public int $screenWidth,
    public int $screenHeight,
    public int $slotCount,
    int $maximumWidth = 110,
    int $infoHeight = 3,
    int $helpHeight = 4,
  ) {
    $this->width = min($maximumWidth, $screenWidth);
    $chromeHeight = min($infoHeight + $helpHeight, max(4, $screenHeight - SaveSlotWindow::HEIGHT));
    $this->infoHeight = min($infoHeight, $chromeHeight - 2);
    $this->helpHeight = $chromeHeight - $this->infoHeight;
    $this->visibleCount = min(max(1, $slotCount),
      max(1, intdiv(max(0, $screenHeight - $chromeHeight), SaveSlotWindow::HEIGHT)));
    $height = $chromeHeight + SaveSlotWindow::HEIGHT * $this->visibleCount;
    $this->left = max(0, intdiv($screenWidth - $this->width, 2));
    $this->top = max(0, intdiv($screenHeight - $height, 2));
  }

  /** @return array{int, int} First inclusive, last exclusive. */
  public function getVisibleRange(int $activeIndex): array
  {
    $index = max(0, min($activeIndex, $this->slotCount - 1));
    $first = intdiv($index, $this->visibleCount) * $this->visibleCount;
    return [$first, min($this->slotCount, $first + $this->visibleCount)];
  }
}
