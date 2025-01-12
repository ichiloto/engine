<?php

namespace Ichiloto\Engine\UI;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;

/**
 * The UI manager.
 */
class UIManager implements CanRender, CanUpdate, CanResume, CanStart
{
  /**
   * @var UIManager The instance of the UI manager.
   */
  protected static UIManager $instance;
  /**
   * @var LocationHUDWindow The location HUD window.
   */
  public LocationHUDWindow $locationHUDWindow;
  /**
   * @var ItemList<UIElementInterface> The UI elements.
   */
  protected(set) ItemList $uiElements;

  /**
   * UIManager constructor.
   */
  protected function __construct(protected(set) Game $game)
  {
    $this->uiElements = new ItemList(UIElementInterface::class);
  }

  /**
   * Returns the instance of the UI manager.
   *
   * @param Game $game The game.
   */
  public static function getInstance(Game $game): self
  {
    if (!isset(self::$instance)) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($uiElement->isActive) {
        $uiElement->render();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($uiElement->isActive) {
        $uiElement->erase();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($uiElement->isActive && $uiElement instanceof CanResume) {
        $uiElement->resume();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($uiElement->isActive && $uiElement instanceof CanResume) {
        $uiElement->suspend();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    // Do nothing. The UI manager is always running.
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    // Do nothing. The UI manager is always running.
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // Do nothing. The UI manager is always running.
  }
}