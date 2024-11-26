<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

class Camera implements CanStart, CanResume, CanRender, CanUpdate
{
  /**
   * Camera constructor.
   *
   * @param SceneInterface $scene The scene that this camera is rendering.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected int $width = DEFAULT_SCREEN_WIDTH,
    protected int $height = DEFAULT_SCREEN_HEIGHT
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $this->scene->getUI()->start();
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    $this->scene->getUI()->stop();
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    foreach ($this->scene->getRootGameObjects() as $gameObject) {
      if ($gameObject->isActive && $this->canSee($gameObject)) {
        $gameObject->render();
      }
    }

    $this->scene->getUI()->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    foreach ($this->scene->getRootGameObjects() as $gameObject) {
      if ($gameObject->isActive && $this->canSee($gameObject)) {
        $gameObject->erase();
      }
    }

    $this->scene->getUI()->erase();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->scene->getUI()->resume();
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->scene->getUI()->suspend();
    Console::clear();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->scene->getUI()->update();
  }

  /**
   * Checks if a game object is visible.
   *
   * @param GameObject $gameObject The game object to check.
   * @return bool True if the game object is visible, false otherwise.
   */
  public function canSee(GameObject $gameObject): bool
  {
    // TODO: Implement isVisible() method.
    return true;
  }
}