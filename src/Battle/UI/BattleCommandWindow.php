<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Interfaces\CanChangeSelection;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Interfaces\CanFocus;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the command window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCommandWindow extends Window implements CanFocus, CanChangeSelection
{
  /**
   * The width of the window.
   */
  const int WIDTH = 14;
  /**
   * The height of the window.
   */
  const int HEIGHT = 6;
  /**
   * The index of the active command.
   */
  protected(set) int $activeCommandIndex = -1;
  /**
   * @var bool Whether the active command should blink.
   */
  protected bool $blinkActiveSelection = false;
  /**
   * @var int The first visible command index.
   */
  protected int $scrollOffset = 0;
  /**
   * @var string The base title shown above the command list.
   */
  protected string $titleBase = 'Command';
  /**
   * The commands available to the player.
   */
  public array $commands = [] {
    get {
      return $this->commands;
    }
    set {
      $this->commands = $value;
      $this->totalCommands = count($this->commands);
      $this->updateContent();
    }
  }

  protected int $totalCommands = 0;

  /**
   * Creates a new instance of the BattleCommandWindow class.
   *
   * @param BattleScreen $battleScreen The battle screen.
   */
  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft();
    $topMargin = $this->battleScreen->screenDimensions->getTop() + $this->battleScreen->fieldWindow->height;

    $position = new Vector2($leftMargin, $topMargin);

    parent::__construct(
      'Command',
      'i:Info',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
  }

  /**
   * @inheritDoc
   */
  public function focus(): void
  {
    $this->blinkActiveSelection = true;
    $this->scrollOffset = 0;
    $this->activeCommandIndex = $this->totalCommands > 0 ? 0 : -1;
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->blinkActiveSelection = false;
    $this->scrollOffset = 0;
    $this->activeCommandIndex = -1;
    $this->updateContent();
  }

  /**
   * Toggles blink state for the active command without changing the selection.
   *
   * @param bool $blink Whether the active command should blink.
   * @return void
   */
  public function setSelectionBlink(bool $blink): void
  {
    $this->blinkActiveSelection = $blink;
    $this->updateContent();
  }

  public function clear(): void
  {
    $this->commands = [];
    $this->activeCommandIndex = -1;
    $this->totalCommands = 0;
    $this->scrollOffset = 0;
    $this->updateContent();
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = [];
    $availableWidth = $this->getContentWidth();
    $visibleRowCount = $this->getVisibleRowCount();

    $this->syncScrollOffset();
    $this->updateTitle();

    foreach (array_slice($this->commands, $this->scrollOffset, $visibleRowCount, true) as $index => $command) {
      $prefix = $this->activeCommandIndex === $index ? '>' : ' ';
      $line = TerminalText::padRight("$prefix $command", $availableWidth);

      if ($this->activeCommandIndex === $index) {
        $line = $this->battleScreen->styleSelectionLine($line, $this->blinkActiveSelection);
      }

      $content[] = $line;
    }

    $content = array_pad($content, $visibleRowCount, '');
    $this->setContent($content);
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function selectPrevious(): void
  {
    if ($this->totalCommands < 1) {
      return;
    }

    $index = wrap($this->activeCommandIndex - 1, 0, $this->totalCommands - 1);
    $this->activeCommandIndex = $index;
    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function selectNext(): void
  {
    if ($this->totalCommands < 1) {
      return;
    }

    $index = wrap($this->activeCommandIndex + 1, 0, $this->totalCommands - 1);
    $this->activeCommandIndex = $index;
    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * Returns the width available for content inside the window frame.
   *
   * @return int The inner content width.
   */
  protected function getContentWidth(): int
  {
    return max(
      0,
      $this->width - 2 - $this->padding->getLeftPadding() - $this->padding->getRightPadding()
    );
  }

  /**
   * Returns the number of command rows visible at once.
   *
   * @return int The visible row count.
   */
  protected function getVisibleRowCount(): int
  {
    return max(0, $this->height - 2);
  }

  /**
   * Keeps the active command inside the visible scroll window.
   *
   * @return void
   */
  protected function syncScrollOffset(): void
  {
    $visibleRowCount = $this->getVisibleRowCount();

    if ($visibleRowCount < 1 || $this->activeCommandIndex < 0) {
      $this->scrollOffset = 0;
      return;
    }

    if ($this->activeCommandIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeCommandIndex;
      return;
    }

    $lastVisibleIndex = $this->scrollOffset + $visibleRowCount - 1;

    if ($this->activeCommandIndex > $lastVisibleIndex) {
      $this->scrollOffset = $this->activeCommandIndex - $visibleRowCount + 1;
    }
  }

  /**
   * Updates the title with the current command-list page.
   *
   * @return void
   */
  protected function updateTitle(): void
  {
    $visibleRowCount = max(1, $this->getVisibleRowCount());
    $totalPages = max(1, (int)ceil($this->totalCommands / $visibleRowCount));
    $currentPage = $this->activeCommandIndex < 0
      ? 1
      : max(1, (int)floor($this->activeCommandIndex / $visibleRowCount) + 1);

    $this->setTitle(
      $totalPages > 1
        ? sprintf('%s %d/%d', $this->titleBase, $currentPage, $totalPages)
        : $this->titleBase
    );
  }
}
