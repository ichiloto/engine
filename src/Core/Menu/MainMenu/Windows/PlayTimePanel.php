<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

class PlayTimePanel extends Window
{
  protected const int WIDTH = 30;
  protected const int HEIGHT = 3;
  /**
   * The width of the content.
   */
  protected int $contentWidth = 28;

  /**
   * PlayTimePanel constructor.
   *
   * @param Vector2 $position The position of the window.
   * @param BorderPackInterface $borderPack The border pack to use.
   */
  public function __construct(
    Vector2 $position,
    BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    parent::__construct(
      'Play Time',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $borderPack
    );

    $this->contentWidth = self::WIDTH - 4; // 1 for the left border and 1 for the right border.
  }

  /**
   * Updates the time display.
   *
   * @return void
   */
  public function updateTimeDisplay(): void
  {
    $paddedTime = sprintf("%{$this->contentWidth}s ", Time::getPrettyTime());
    $this->setContent([$paddedTime]);
    $this->render();
  }
}