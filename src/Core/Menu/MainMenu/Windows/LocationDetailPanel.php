<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * LocationDetailPanel is the window that displays the name and region of the current location.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class LocationDetailPanel extends Window
{
  /**
   * The width of the window.
   */
  protected const int WIDTH = 30;
  /**
   * The height of the window.
   */
  protected const int HEIGHT = 4;
  /**
   * The width of the content.
   */
  protected int $contentWidth = 28;

  /**
   * LocationDetailPanel constructor.
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
      'Location',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $borderPack
    );

    $this->contentWidth = self::WIDTH - 4;
  }

  /**
   * Sets the location to display.
   *
   * @param string $name The name of the location.
   * @param string $region The region of the location.
   * @return void
   */
  public function setLocation(string $name, string $region): void
  {
    $paddedName = sprintf("%{$this->contentWidth}s", $name);
    $regionName = sprintf("%{$this->contentWidth}s", $region);

    $this->setContent([$paddedName, $regionName]);
    $this->render();
  }
}