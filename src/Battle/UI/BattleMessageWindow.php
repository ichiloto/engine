<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the battle prompt.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleMessageWindow extends Window
{
  public function __construct(
    protected BattleScreen $battleScreen
  )
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft() + 2;
    $topMargin = $this->battleScreen->screenDimensions->getTop() + 1;
    $width = $this->battleScreen->screenDimensions->getWidth() - 4;
    $height = 3;

    $position = new Vector2($leftMargin, $topMargin);

    parent::__construct(
      'Info',
      '',
      $position,
      $width,
      $height,
      $this->battleScreen->borderPack
    );
  }

  /**
   * Shows the window with the given text.
   *
   * @param string $text The text to show.
   */
  public function show(string $text): void
  {
    $lines = explode("\n", $text);
    $this->setContent($lines);
    $this->render();
  }

  /**
   * Hides the window.
   */
  public function hide(): void
  {
    $this->erase();
  }
}