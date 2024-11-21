<?php

namespace Ichiloto\Engine\Scenes;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\UIManager;

abstract class AbstractScene implements SceneInterface
{
  /**
   * Whether the scene has started.
   *
   * @var bool
   */
  protected bool $started = false;
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
  /**
   * The UI manager.
   *
   * @var UIManager
   */
  protected UIManager $uiManager;

  /**
   * AbstractScene constructor.
   *
   * @param SceneManager $sceneManager The scene manager.
   * @param string $name The name of the scene.
   */
  public function __construct(
    protected SceneManager $sceneManager,
    protected string $name,
  )
  {
    $this->uiManager = UIManager::getInstance();
    $this->camera = new Camera($this);
  }

  /**
   * @inheritDoc
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * @inheritDoc
   */
  public function getRootGameObjects(): array
  {
    return $this->rootGameObjects;
  }

  /**
   * @inheritDoc
   */
  public function getUI(): UIManager
  {
    return $this->uiManager;
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->camera->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->camera->erase();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->camera->resume();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->resume();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->camera->suspend();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->suspend();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $this->camera->start();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->start();
      }
    }

    $this->started = true;
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    $this->camera->stop();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->stop();
      }
    }

    $this->started = false;
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->camera->update();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive()) {
        $gameObject->update();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function isStarted(): bool
  {
    return $this->started;
  }

  /**
   * @inheritDoc
   */
  public function getGame(): Game
  {
    return $this->sceneManager->getGame();
  }
}