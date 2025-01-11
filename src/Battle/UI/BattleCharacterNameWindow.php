<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Interfaces\CanFocus;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

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

    foreach ($this->names as $index => $name) {
      $prefix = $this->activeIndex === $index ? '>' : ' ';
      $content[] = " $prefix $name";
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
    $this->activeIndex = 0;
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    // Do nothing
  }
}