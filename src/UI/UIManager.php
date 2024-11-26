<?php

namespace Ichiloto\Engine\UI;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Vector2;

/**
 * The UI manager.
 */
class UIManager implements CanRender, CanUpdate, CanResume, CanStart
{
  /**
   * @var UIManager The instance of the UI manager.
   */
  protected static UIManager $instance;

  public LocationHUDWindow $locationHUDWindow;
  /**
   * @var ItemList<CanRender> The UI elements.
   */
  protected ItemList $uiElements;

  /**
   * UIManager constructor.
   */
  protected function __construct(protected(set) Game $game)
  {
    $this->locationHUDWindow = new LocationHUDWindow(new Vector2(0, 0), MovementHeading::NONE);
    $this->uiElements = new ItemList(CanRender::class);
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
      $uiElement->render();
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    foreach ($this->uiElements as $uiElement) {
      $uiElement->erase();
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->locationHUDWindow->render();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->locationHUDWindow->erase();
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