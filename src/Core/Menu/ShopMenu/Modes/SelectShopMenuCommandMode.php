<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Modes;

use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Represents the select shop menu command mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Modes
 */
class SelectShopMenuCommandMode extends ShopMenuMode
{
  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->handleActions();
    $this->handleNavigation();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    // TODO: Implement enter() method.
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // TODO: Implement exit() method.
  }

  /**
   * Handles the navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $h = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($h) > 0) {
      if ($h > 0) {
        $this->state->commandPanel->selectNext();
      } else {
        $this->state->commandPanel->selectPrevious();
      }
    }
  }

  /**
   * Handles the actions.
   *
   * @return void
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("back")) {
      $this->state->setState($this->state->getGameScene()->fieldState);
    }
  }
}