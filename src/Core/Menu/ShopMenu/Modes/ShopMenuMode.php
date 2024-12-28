<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Modes;

use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\Scenes\Game\States\ShopState;

/**
 * Represents the shop menu mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Modes
 */
abstract class ShopMenuMode implements MainMenuModeInterface
{
  /**
   * ShopMenuMode constructor.
   *
   * @param ShopState $state The shop state.
   */
  public function __construct(protected ShopState $state)
  {
  }
}