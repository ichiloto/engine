<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the battle character name window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCharacterNameWindow extends Window
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
  protected int $activeIndex = -1;

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
}