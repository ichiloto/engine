<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Class SelectItemTargetMode. Represents the target selection mode of the item menu.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
class SelectItemTargetMode extends ItemMenuMode
{
  /**
   * @var ItemMenuMode|null The previous mode.
   */
  public ?ItemMenuMode $previousMode = null;

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      $previousMode = new SelectIemMenuCommandMode($this->state);
      if ($this->previousMode) {
        $previousMode = $this->previousMode;
      }

      $this->state->setMode($previousMode);
    }

    if (Input::isButtonDown("confirm")) {
      alert(sprintf("Used %s on %s", $this->state->selectionPanel->activeItem?->name, $this->state->targetSelectionPanel->activeCharacter?->name));
    }

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->selectNext();
      } else {
        $this->selectPrevious();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->targetSelectionPanel->setTargets($this->state->getGameScene()->party->members->toArray());
    $this->state->targetSelectionPanel->focus();
    $this->state->statusPanel->setTarget($this->state->targetSelectionPanel->activeCharacter);
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->state->targetSelectionPanel->setTargets([]);
    $this->state->targetSelectionPanel->blur();
    $this->state->statusPanel->setTarget(null);
  }

  /**
   * Selects the previous target.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $activeIndex = $this->state->targetSelectionPanel->activeIndex - 1;
    $this->state->targetSelectionPanel->setActiveItemIndex(wrap($activeIndex, 0, $this->state->targetSelectionPanel->totalTargets - 1));
    $this->state->statusPanel->setTarget($this->state->targetSelectionPanel->activeCharacter);
  }

  /**
   * Selects the next target.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $activeIndex = $this->state->targetSelectionPanel->activeIndex + 1;
    $this->state->targetSelectionPanel->setActiveItemIndex(wrap($activeIndex, 0, $this->state->targetSelectionPanel->totalTargets - 1));
    $this->state->statusPanel->setTarget($this->state->targetSelectionPanel->activeCharacter);
  }
}