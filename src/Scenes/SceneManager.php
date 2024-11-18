<?php

namespace Ichiloto\Engine\Scenes;

use Assegai\Collections\ItemList;
use Exception;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
use Ichiloto\Engine\Events\Enumerations\SceneEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\SceneEvent;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

class SceneManager implements CanStart, CanRender, CanUpdate, SingletonInterface
{
  /**
   * The instance of this singleton.
   * @var SceneManager|null
   */
  protected static ?SceneManager $instance = null;
  /**
   * @var EventManager The event manager.
   */
  protected EventManager $eventManager;
  /**
   * The scenes in the scene manager.
   * @var ItemList<SceneInterface>
   */
  protected ItemList $scenes;
  /**
   * The current scene.
   * @var SceneInterface|null
   */
  protected ?SceneInterface $currentScene = null;

  /**
   * SceneManager constructor.
   */
  private function __construct()
  {
    $this->scenes = new ItemList(SceneInterface::class);
    $this->eventManager = EventManager::getInstance();
  }

  /**
   * @inheritDoc
   */
  public static function getInstance(): SceneManager
  {
    if (self::$instance === null) {
      self::$instance = new SceneManager();
    }

    return self::$instance;
  }

  /**
   * Add scenes to the scene manager.
   *
   * @param SceneInterface ...$scenes The scenes to add.
   * @return $this The scene manager.
   */
  public function addScenes(SceneInterface ...$scenes): self
  {
    foreach ($scenes as $scene) {
      $this->scenes->add($scene);
      $scene->start();
    }

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $this->currentScene?->start();
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    foreach ($this->scenes as $scene) {
      $scene->stop();
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->currentScene?->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->currentScene?->erase();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->currentScene?->update();
  }

  /**
   * Load a scene.
   *
   * @param string|int $index The index of the scene to load.
   * @return SceneManager The scene manager.
   * @throws NotFoundException
   */
  public function loadScene(string|int $index): self
  {
    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_START, $this->currentScene));

    $sceneToLoad = match(true) {
      is_int($index) => $this->scenes->toArray()[$index] ?? throw new NotFoundException($index),
      default => $this->scenes->find(fn(SceneInterface $scene) => $scene::class === $index) ?? throw new NotFoundException($index),
    };

    $this->currentScene?->suspend();
    $this->currentScene = $sceneToLoad;
    $this->currentScene?->resume();

    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_END, $this->currentScene));

    return $this;
  }

  /**
   * Unload a scene.
   *
   * @param SceneInterface $scene The scene to unload.
   * @return SceneManager The scene manager.
   */
  public function unloadScene(SceneInterface $scene): self
  {
    if ($this->scenes->contains($scene)) {
      $this->scenes->remove($scene);
      $scene->stop();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::UNLOAD, $this->currentScene));
    }

    return $this;
  }

  /**
   * Load the game over scene.
   */
  public function loadGameOverScene(): void
  {
    // TODO: Implement loadGameOverScene() method.
    throw new Exception('Method not implemented.');
  }

  /**
   * Return the current scene.
   *
   * @return SceneInterface|null The current scene.
   */
  public function getCurrentScene(): ?SceneInterface
  {
    return $this->currentScene;
  }
}