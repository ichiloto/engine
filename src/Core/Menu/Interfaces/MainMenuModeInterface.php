<?php

namespace Ichiloto\Engine\Core\Menu\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanUpdate;

/**
 * MainMenuModeInterface is an interface implemented by all classes that represent a main menu mode.
 *
 * @package Ichiloto\Engine\Core\Menu\Interfaces
 */
interface MainMenuModeInterface extends CanUpdate
{
  /**
   * Enters the main menu mode.
   */
  public function enter(): void;

  /**
   * Exits the main menu mode.
   */
  public function exit(): void;
}