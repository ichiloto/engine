<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the battle character status window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCharacterStatusWindow extends Window
{
  /**
   * The width of the window.
   */
  const int WIDTH = 35;
  /**
   * The height of the window.
   */
  const int HEIGHT = 6;

  /**
   * Creates a new instance of the battle character status window.
   *
   * @param BattleScreen $battleScreen The battle screen.
   */
  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft() +
      $this->battleScreen->commandWindow->width +
      $this->battleScreen->commandContextWindow->width +
      $this->battleScreen->characterNameWindow->width;
    $topMargin = $this->battleScreen->screenDimensions->getTop() +
      $this->battleScreen->fieldWindow->height;

    $position = new Vector2($leftMargin, $topMargin);
    parent::__construct(
      'HP═══════════════════MP',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
  }
}