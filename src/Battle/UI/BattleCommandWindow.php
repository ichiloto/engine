<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Interfaces\CanChangeSelection;
use Ichiloto\Engine\Core\Vector2;
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
    // TODO: Implement focus() method.
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    // TODO: Implement blur() method.
  }

  public function clear(): void
  {
    $this->setContent([]);
    $this->render();
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    // TODO: Implement updateContent() method.
  }

  /**
   * @inheritDoc
   */
  public function selectPrevious(): void
  {
    $index = wrap($this->activeCommandIndex - 1, 0, $this->totalCommands - 1);
    $this->activeCommandIndex = $index;
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function selectNext(): void
  {
    $index = wrap($this->activeCommandIndex + 1, 0, $this->totalCommands - 1);
    $this->activeCommandIndex = $index;
    $this->updateContent();
  }
}