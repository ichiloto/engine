<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;

/**
 * Displays the result of a battle.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleResultWindow extends Window
{
  const int WIDTH = 64;
  const int HEIGHT = 8;

  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft() + intval((BattleScreen::WIDTH - self::WIDTH) / 2);
    $topMargin = $this->battleScreen->screenDimensions->getTop() + intval((BattleScreen::HEIGHT - self::HEIGHT) / 2);

    parent::__construct(
      '',
      'enter:Continue',
      new Vector2($leftMargin, $topMargin),
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack,
      WindowAlignment::middleLeft()
    );
  }

  /**
   * Displays the provided battle result.
   *
   * @param BattleResult $result The battle result to display.
   * @return void
   */
  public function display(BattleResult $result): void
  {
    $content = [];
    $this->setTitle($result->title);

    foreach ($result->lines as $line) {
      $content = array_merge($content, explode("\n", wrap_text($line, self::WIDTH - 4)));
    }

    $content = array_slice($content, 0, self::HEIGHT - 2);
    $content = array_pad($content, self::HEIGHT - 2, '');
    $this->setContent($content);
    $this->render();
  }
}
