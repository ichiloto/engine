<?php

namespace Ichiloto\Engine\UI\Windows;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\SlimBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

class DebugWindow extends Window
{
  protected const int WIDTH = 40;
  protected const int HEIGHT = 5;

  protected int $leftMargin {
    get {
      return config(PlaySettings::class, 'width', DEFAULT_SCREEN_WIDTH) - self::WIDTH;
    }
  }

  protected int $topMargin {
    get {
      return 0;
    }
  }

  public function __construct(protected Game $game)
  {
    parent::__construct(
      'Debug',
      '',
      new Vector2($this->leftMargin, $this->topMargin),
      self::WIDTH,
      self::HEIGHT,
      new SlimBorderPack(),
      padding: new WindowPadding(rightPadding: 1, leftPadding: 1)
    );
  }
}