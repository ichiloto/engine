<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;

/**
 * Class ItemMenuMode. Represents an item menu mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
abstract class ItemMenuMode implements MainMenuModeInterface
{
  /**
   * ItemMenuMode constructor.
   *
   * @param ItemMenuState $state The item menu state.
   */
  public function __construct(protected ItemMenuState $state)
  {
  }
}