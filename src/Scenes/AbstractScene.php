<?php

namespace Ichiloto\Engine\Scenes;

use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Rendering\Camera;

class AbstractScene implements Interfaces\SceneInterface
{
  /**
   * The root game objects.
   *
   * @var GameObject[]
   */
  protected array $rootGameObjects = [];
  /**
   * The camera of the scene.
   *
   * @var Camera
   */
  protected Camera $camera;

  public function __construct(
    protected string $name
  )
  {
    $this->camera = new Camera($this);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    // TODO: Implement render() method.
    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->render();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    // TODO: Implement erase() method.
    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->erase();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    // TODO: Implement resume() method.
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    // TODO: Implement suspend() method.
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    // TODO: Implement start() method.
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    // TODO: Implement stop() method.
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // TODO: Implement update() method.
  }

  /**
   * @inheritDoc
   */
  public function getRootGameObjects(): array
  {
    return $this->rootGameObjects;
  }
}