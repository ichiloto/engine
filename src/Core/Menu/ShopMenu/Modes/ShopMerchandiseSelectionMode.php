<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Modes;

use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopMenuMode;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Represents the shop item selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Modes
 */
class ShopMerchandiseSelectionMode extends ShopMenuMode
{
  /**
   * The previous mode.
   *
   * @var ShopMenuMode|null
   */
  public ?ShopMenuMode $previousMode = null;

  /**
   * @var int The active index.
   */
  public int $totalMerchandise {
    get {
      return count($this->state->merchandise);
    }
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      if ($this->previousMode) {
        $this->state->setMode($this->previousMode);
      }
    }

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->selectNextItem();
      } else {
        $this->selectPreviousItem();
      }
    }
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

  private function selectNextItem(): void
  {
    $index = wrap($this->state->shopMenu->activeIndex + 1, 0, $this->totalMerchandise - 1);
    $this->state->shopMenu->setActiveItemByIndex($index);
    $this->state->mainPanel->updateContent();
  }
}