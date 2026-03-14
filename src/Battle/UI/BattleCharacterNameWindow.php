<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Interfaces\CanFocus;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the battle character name window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCharacterNameWindow extends Window implements CanFocus
{
  /**
   * The width of the window.
   */
  const int WIDTH = 24;
  /**
   * The height of the window.
   */
  const int HEIGHT = 6;
  /**
   * @var int The active index.
   */
  public int $activeIndex = -1 {
    get {
      return $this->activeIndex;
    }

    set {
      $this->activeIndex = $value;
      $this->updateContent();
    }
  }
  /**
   * @var string[] The names of the characters.
   */
  protected(set) array $names = [];
  /**
   * @var bool Whether the active name should blink to show pending input.
   */
  protected bool $blinkActiveSelection = false;

  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft() + $this->battleScreen->commandWindow->width + $this->battleScreen->commandContextWindow->width;
    $topMargin = $this->battleScreen->screenDimensions->getTop() + $this->battleScreen->fieldWindow->height;

    $position = new Vector2($leftMargin, $topMargin);
    parent::__construct(
      'Name',
      'c:Cancel',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
  }

  /**
   * Sets the names of the characters.
   *
   * @param string[] $names The names of the characters.
   */
  public function setNames(array $names): void
  {
    $this->names = $names;
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

    foreach ($this->names as $index => $name) {
      $prefix = $this->activeIndex === $index ? '>' : ' ';
      $line = TerminalText::padRight(" $prefix $name", $availableWidth);

      if ($this->activeIndex === $index) {
        $line = $this->battleScreen->styleSelectionLine($line, $this->blinkActiveSelection);
      }

      $content[] = $line;
    }

    $content = array_pad($content, self::HEIGHT - 2, '');
    $this->setContent($content);
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function focus(): void
  {
    $this->setActiveSelection(0, blink: true);
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->setActiveSelection(-1);
  }

  /**
   * Updates the active name and whether it should blink.
   *
   * @param int $index The active name index.
   * @param bool $blink Whether the active name should blink.
   * @return void
   */
  public function setActiveSelection(int $index, bool $blink = false): void
  {
    $this->blinkActiveSelection = $blink;
    $this->activeIndex = $index;
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
