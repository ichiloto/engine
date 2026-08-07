<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuMode;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Represents the mode for equipping a character.
 */
class EquipmentSlotSelectionMode extends EquipmentMenuMode
{
  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->equipmentAssignmentPanel->setActiveSlotByIndex(0);
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->state->equipmentAssignmentPanel->setActiveSlotByIndex(-1);
  }

  /**
   * Handles the navigation of the equipment assignment panel.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      play_sound(SystemSound::CURSOR);

      if ($v > 0) {
        $this->state->equipmentAssignmentPanel->selectNextSlot();
      } else {
        $this->state->equipmentAssignmentPanel->selectPreviousSlot();
      }
    }
  }

  /**
   * Handle the actions of the equipment assignment panel.
   *
   * @return void
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("cancel")) {
      play_sound(SystemSound::CANCEL);
      $this->state->setMode(new EquipmentMenuCommandSelectionMode($this->state));
    }

    if (Input::isButtonDown("confirm")) {
      play_sound(SystemSound::CONFIRM);
      $mode = new EquipmentSelectionMode($this->state);
      $mode->character = $this->state->character;
      $mode->equipmentSlot = $this->state->equipmentAssignmentPanel->activeSlot;
      $mode->previousMode = $this;
      $this->state->setMode($mode);
    }
  }
}