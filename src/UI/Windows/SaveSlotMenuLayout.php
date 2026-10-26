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
  public int $slotHeight;
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
    if ($screenWidth < 40 || $screenHeight < 8) {
      throw new \InvalidArgumentException('Terminal Save/Load requires at least 40 columns and 8 rows.');
    }
    $this->width = min($maximumWidth, $screenWidth);
    $chromeHeight = min($infoHeight + $helpHeight, max(4, $screenHeight - SaveSlotWindow::HEIGHT));
    $this->infoHeight = min($infoHeight, $chromeHeight - 2);
    $this->helpHeight = $chromeHeight - $this->infoHeight;
    $available = max(0, $screenHeight - $chromeHeight);
    $this->slotHeight = $available >= 3 * SaveSlotPage::SIZE && $available < SaveSlotWindow::HEIGHT * SaveSlotPage::SIZE
      ? 3 : SaveSlotWindow::HEIGHT;
    $this->visibleCount = min(SaveSlotPage::SIZE, max(1, $slotCount), max(1, intdiv($available, $this->slotHeight)));
    $height = $chromeHeight + $this->slotHeight * $this->visibleCount;
    $this->left = max(0, intdiv($screenWidth - $this->width, 2));
    $this->top = max(0, intdiv($screenHeight - $height, 2));
  }

  /** @return array{int, int} First inclusive, last exclusive. */
  public function getVisibleRange(int $activeIndex): array
  {
    return SaveSlotPage::getVisibleRange($this->slotCount, $activeIndex, $this->visibleCount);
  }
}
