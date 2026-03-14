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
    $this->activeCommandIndex = $this->totalCommands > 0 ? 0 : -1;
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->blinkActiveSelection = false;
    $this->activeCommandIndex = -1;
    $this->updateContent();
  }

  public function clear(): void
  {
    $this->commands = [];
    $this->activeCommandIndex = -1;
    $this->totalCommands = 0;
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

    foreach ($this->commands as $index => $command) {
      $prefix = $this->activeCommandIndex === $index ? '>' : ' ';
      $line = TerminalText::padRight("$prefix $command", $availableWidth);

      if ($this->activeCommandIndex === $index) {
        $line = $this->battleScreen->styleSelectionLine($line, $this->blinkActiveSelection);
      }

      $content[] = $line;
    }

    $content = array_pad($content, $this->height - 2, '');
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
}
