<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes;

use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;

/**
 * Represents the equipment menu mode.
 *
 * @package Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes
 */
abstract class EquipmentMenuMode implements MainMenuModeInterface
{
  /**
   * Constructs a new instance of this EquipmentMenuMode.
   *
   * @param EquipmentMenuState $state The equipment menu state.
   */
  public function __construct(protected EquipmentMenuState $state)
  {
  }
}