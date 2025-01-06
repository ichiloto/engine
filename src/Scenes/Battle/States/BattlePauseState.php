<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Symfony\Component\Console\Output\ConsoleOutput;

class BattlePauseState extends BattleSceneState
{
  const string PAUSE_TEXT = "PAUSED";

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (Input::isButtonDown("pause")) {
      $this->setState($this->scene->runState);
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $pauseTextLength = strlen(self::PAUSE_TEXT);
    $leftMargin = intval((get_screen_width() - $pauseTextLength) / 2);
    $topMargin = intval((get_screen_height() - 1) / 2);

    Console::cursor()->moveTo($leftMargin, $topMargin);
    $output = new ConsoleOutput();
    $output->write(self::PAUSE_TEXT);
  }
}