<?php

namespace Ichiloto\Engine\Core\Menu\MagicMenu\Windows;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Renders the tab strip for the field magic menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MagicMenu\Windows
 */
class MagicTabPanel extends Window
{
  /**
   * @var string[] The visible tab labels.
   */
  protected array $tabs = [];
  /**
   * @var int The selected tab index.
   */
  protected int $activeIndex = 0;

  /**
   * Sets the tab labels and current selection.
   *
   * @param string[] $tabs The tab labels to render.
   * @param int $activeIndex The active tab index.
   * @return void
   */
  public function setTabs(array $tabs, int $activeIndex): void
  {
    $this->tabs = array_values(array_filter($tabs, 'is_string'));
    $this->activeIndex = clamp($activeIndex, 0, max(0, count($this->tabs) - 1));
    $this->updateContent();
  }

  /**
   * Rebuilds the tab-strip content.
   *
   * @return void
   */
  protected function updateContent(): void
  {
    $availableWidth = max(0, $this->width - 4);
    $segmentWidth = max(1, intdiv($availableWidth, max(1, count($this->tabs))));
    $line = '';

    foreach ($this->tabs as $index => $tab) {
      $chunk = TerminalText::padCenter($tab, $segmentWidth);

      if ($index === $this->activeIndex) {
        $chunk = SelectionStyle::apply($chunk);
      }

      $line .= $chunk;
    }

    $line = TerminalText::padRight(TerminalText::truncateToWidth($line, $availableWidth), $availableWidth);
    $this->setContent([$line]);
    $this->render();
  }
}
