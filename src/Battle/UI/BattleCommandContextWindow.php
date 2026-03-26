<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Core\Interfaces\CanChangeSelection;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Interfaces\CanFocus;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Renders the active battle submenu and handles scrolling through its options.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCommandContextWindow extends Window implements CanFocus, CanChangeSelection
{
  const int WIDTH = 62;
  const int HEIGHT = 6;
  /**
   * @var BattleCommandOption[] The current submenu options.
   */
  protected array $items = [];
  /**
   * @var int The active submenu index.
   */
  protected int $activeIndex = -1;
  /**
   * @var bool Whether the active submenu option should blink.
   */
  protected bool $blinkActiveSelection = false;
  /**
   * @var int The first visible submenu item index.
   */
  protected int $scrollOffset = 0;
  /**
   * @var string The base title shown above the submenu.
   */
  protected string $titleBase = '';
  /**
   * @var string The message shown when the submenu is empty.
   */
  protected string $emptyMessage = '';

  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft() + $this->battleScreen->commandWindow->width;
    $topMargin = $this->battleScreen->screenDimensions->getTop() + $this->battleScreen->fieldWindow->height;

    $position = new Vector2($leftMargin, $topMargin);

    parent::__construct(
      '',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
  }

  /**
   * Replaces the submenu contents and redraws the panel.
   *
   * @param BattleCommandOption[] $items The submenu options.
   * @param string $title The submenu title.
   * @param string $emptyMessage The empty-state message.
   * @return void
   */
  public function setItems(array $items, string $title = '', string $emptyMessage = 'Nothing available.'): void
  {
    $this->items = array_values(array_filter($items, static fn(mixed $item): bool => $item instanceof BattleCommandOption));
    $this->titleBase = $title;
    $this->emptyMessage = $emptyMessage;
    $this->activeIndex = empty($this->items) ? -1 : 0;
    $this->scrollOffset = 0;
    $this->updateContent();
  }

  /**
   * Returns the currently selected submenu option.
   *
   * @return BattleCommandOption|null The active submenu option.
   */
  public function getActiveItem(): ?BattleCommandOption
  {
    return $this->items[$this->activeIndex] ?? null;
  }

  /**
   * Returns whether the submenu currently has any selectable options.
   *
   * @return bool True when the submenu contains at least one option.
   */
  public function hasItems(): bool
  {
    return ! empty($this->items);
  }

  /**
   * Returns the empty-state message shown when no submenu items are available.
   *
   * @return string The empty-state message.
   */
  public function getEmptyMessage(): string
  {
    return $this->emptyMessage;
  }

  /**
   * @inheritDoc
   */
  public function focus(): void
  {
    $this->blinkActiveSelection = true;

    if ($this->hasItems() && $this->activeIndex < 0) {
      $this->activeIndex = 0;
    }

    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->blinkActiveSelection = false;
    $this->updateContent();
  }

  /**
   * Toggles blinking on the active submenu option without changing selection.
   *
   * @param bool $blink Whether the active submenu option should blink.
   * @return void
   */
  public function setSelectionBlink(bool $blink): void
  {
    $this->blinkActiveSelection = $blink;
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function selectPrevious(): void
  {
    if (! $this->hasItems()) {
      return;
    }

    $this->activeIndex = wrap($this->activeIndex - 1, 0, count($this->items) - 1);
    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function selectNext(): void
  {
    if (! $this->hasItems()) {
      return;
    }

    $this->activeIndex = wrap($this->activeIndex + 1, 0, count($this->items) - 1);
    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * Clears the context window while preserving its reserved layout space.
   *
   * @return void
   */
  public function clear(): void
  {
    $this->items = [];
    $this->activeIndex = -1;
    $this->scrollOffset = 0;
    $this->blinkActiveSelection = false;
    $this->titleBase = '';
    $this->emptyMessage = '';
    $this->setTitle('');
    $this->setHelp('');
    $this->setContent(array_fill(0, self::HEIGHT - 2, ''));
    $this->render();
  }

  /**
   * Rebuilds the visible submenu lines.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $visibleRowCount = $this->getVisibleRowCount();
    $availableWidth = $this->getContentWidth();
    $this->syncScrollOffset();
    $this->updateTitle();
    $this->setHelp($this->hasItems() ? 'enter:Select i:Info c:Back' : 'i:Info c:Back');

    if (! $this->hasItems()) {
      $content = array_fill(0, $visibleRowCount, '');
      $content[0] = TerminalText::padRight($this->emptyMessage, $availableWidth);
      $this->setContent($content);
      $this->render();
      return;
    }

    $content = [];

    foreach (array_slice($this->items, $this->scrollOffset, $visibleRowCount, true) as $index => $item) {
      $prefix = $this->activeIndex === $index ? '>' : ' ';
      $line = TerminalText::padRight("{$prefix} {$item->label}", $availableWidth);

      if ($this->activeIndex === $index) {
        $line = $this->battleScreen->styleSelectionLine($line, $this->blinkActiveSelection);
      }

      $content[] = $line;
    }

    $content = array_pad($content, $visibleRowCount, '');
    $this->setContent($content);
    $this->render();
  }

  /**
   * Returns the number of visible submenu rows.
   *
   * @return int The visible row count.
   */
  protected function getVisibleRowCount(): int
  {
    return max(0, self::HEIGHT - 2);
  }

  /**
   * Returns the width available for submenu content.
   *
   * @return int The visible content width.
   */
  protected function getContentWidth(): int
  {
    return max(
      0,
      $this->width - 2 - $this->padding->getLeftPadding() - $this->padding->getRightPadding()
    );
  }

  /**
   * Keeps the active submenu item within the visible scroll window.
   *
   * @return void
   */
  protected function syncScrollOffset(): void
  {
    $visibleRowCount = $this->getVisibleRowCount();

    if ($visibleRowCount < 1 || $this->activeIndex < 0) {
      $this->scrollOffset = 0;
      return;
    }

    if ($this->activeIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeIndex;
      return;
    }

    $lastVisibleIndex = $this->scrollOffset + $visibleRowCount - 1;

    if ($this->activeIndex > $lastVisibleIndex) {
      $this->scrollOffset = $this->activeIndex - $visibleRowCount + 1;
    }
  }

  /**
   * Updates the title to include the current submenu page when scrolling is needed.
   *
   * @return void
   */
  protected function updateTitle(): void
  {
    $visibleRowCount = max(1, $this->getVisibleRowCount());
    $totalPages = max(1, (int)ceil(count($this->items) / $visibleRowCount));
    $currentPage = $this->activeIndex < 0
      ? 1
      : max(1, (int)floor($this->activeIndex / $visibleRowCount) + 1);

    $this->setTitle(
      $totalPages > 1 && $this->titleBase !== ''
        ? sprintf('%s %d/%d', $this->titleBase, $currentPage, $totalPages)
        : $this->titleBase
    );
  }
}
