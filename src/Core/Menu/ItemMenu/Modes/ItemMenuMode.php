<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Class ItemMenuMode. Represents an item menu mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
abstract class ItemMenuMode implements MainMenuModeInterface
{
  /**
   * @var Party The party.
   */
  protected Party $party {
    get {
      return $this->state->getGameScene()->party;
    }
  }
  /**
   * @var Inventory The inventory.
   */
  protected Inventory $inventory {
    get {
      return $this->party->inventory;
    }
  }
  /**
   * ItemMenuMode constructor.
   *
   * @param ItemMenuState $state The item menu state.
   */
  public function __construct(protected ItemMenuState $state)
  {
  }

  protected function navigateItems(): void
  {
    $horizontal = Input::getAxis(AxisName::HORIZONTAL);
    $vertical = Input::getAxis(AxisName::VERTICAL);
    if ($horizontal != 0) {
      play_sound(SystemSound::CURSOR);
      $this->state->selectionPanel->changePage($horizontal <=> 0);
    } elseif ($vertical != 0) {
      play_sound(SystemSound::CURSOR);
      if ($vertical > 0) { $this->state->selectionPanel->selectNext(); }
      else { $this->state->selectionPanel->selectPrevious(); }
    }
  }
}
