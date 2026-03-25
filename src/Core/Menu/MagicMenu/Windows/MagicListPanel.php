<?php

namespace Ichiloto\Engine\Core\Menu\MagicMenu\Windows;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Renders a scrollable list of spell-related entries.
 *
 * @package Ichiloto\Engine\Core\Menu\MagicMenu\Windows
 */
class MagicListPanel extends Window
{
  /**
   * @var string[] The rendered entries.
   */
  protected array $entries = [];
  /**
   * @var int The selected entry index.
   */
  protected int $activeIndex = -1;
  /**
   * @var int The current top-of-window scroll offset.
   */
  protected int $scrollOffset = 0;

  /**
   * Sets the visible entries and active selection.
   *
   * @param string[] $entries The list entries to render.
   * @param int $activeIndex The selected entry index.
   * @return void
   */
  public function setEntries(array $entries, int $activeIndex = -1): void
  {
    $this->entries = array_values(array_map('strval', $entries));
    $this->activeIndex = empty($this->entries)
      ? -1
      : clamp($activeIndex, 0, count($this->entries) - 1);

    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * Rebuilds the list content while keeping the selection visible.
   *
   * @return void
   */
  protected function updateContent(): void
  {
    $visibleRows = max(1, $this->height - 2);
    $availableWidth = max(0, $this->width - 4);
    $content = array_fill(0, $visibleRows, '');
    $visibleEntries = array_slice($this->entries, $this->scrollOffset, $visibleRows, true);

    foreach ($visibleEntries as $row => $entry) {
      $actualIndex = $this->scrollOffset + $row;
      $line = TerminalText::padRight(
        TerminalText::truncateToWidth($entry, $availableWidth),
        $availableWidth
      );

      if ($actualIndex === $this->activeIndex) {
        $line = SelectionStyle::apply($line);
      }

      $content[$row] = $line;
    }

    $this->setContent($content);
    $this->render();
  }

  /**
   * Keeps the active row inside the visible viewport.
   *
   * @return void
   */
  protected function syncScrollOffset(): void
  {
    $visibleRows = max(1, $this->height - 2);
    $maxOffset = max(0, count($this->entries) - $visibleRows);

    if ($this->activeIndex < 0) {
      $this->scrollOffset = 0;
      return;
    }

    if ($this->activeIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeIndex;
    }

    if ($this->activeIndex >= ($this->scrollOffset + $visibleRows)) {
      $this->scrollOffset = $this->activeIndex - $visibleRows + 1;
    }

    $this->scrollOffset = clamp($this->scrollOffset, 0, $maxOffset);
  }
}
