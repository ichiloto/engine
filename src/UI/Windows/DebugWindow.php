<?php

namespace Ichiloto\Engine\UI\Windows;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\SlimBorderPack;
use Ichiloto\Engine\Util\Config\PlaySettings;

/**
 * Represents a debug window.
 *
 * @package Ichiloto\Engine\UI\Windows
 */
class DebugWindow extends Window
{
  /**
   * The width of the window.
   */
  protected const int WIDTH = 40;
  /**
   * The height of the window.
   */
  protected const int HEIGHT = 5;
  /**
   * The default screen width.
   */
  protected int $leftMargin {
    get {
      return config(PlaySettings::class, 'width', DEFAULT_SCREEN_WIDTH) - self::WIDTH;
    }
  }
  /**
   * @var int $topMargin The default screen height.
   */
  protected int $topMargin {
    get {
      return config(PlaySettings::class, 'height', DEFAULT_SCREEN_HEIGHT) - self::HEIGHT;
    }
  }

  /**
   * DebugWindow constructor.
   *
   * @param Game $game The game instance.
   */
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