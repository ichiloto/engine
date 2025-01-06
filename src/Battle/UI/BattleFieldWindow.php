<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the battlefield window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleFieldWindow extends Window
{
  /**
   * The width of the window.
   */
  const int WIDTH = 135;
  /**
   * The height of the window.
   */
  const int HEIGHT = 30;

  /**
   * Creates a new instance of the battlefield window.
   *
   * @param BattleScreen $battleScreen The battle screen.
   */
  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft();
    $topMargin = $this->battleScreen->screenDimensions->getTop();

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
}