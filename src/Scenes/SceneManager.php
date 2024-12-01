<?php

namespace Ichiloto\Engine\Scenes;

use Assegai\Collections\ItemList;
use Exception;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Events\Enumerations\SceneEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\SceneEvent;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

/**
 * SceneManager is a class that manages scenes.
 *
 * @package Ichiloto\Engine\Scenes
 */
class SceneManager implements CanStart, CanRender, CanUpdate
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
   * @var SaveManager The save manager.
   */
  protected(set) SaveManager $saveManager;
  /**
   * The scenes in the scene manager.
   * @var ItemList<SceneInterface>
   */
  protected ItemList $scenes;
  /**
   * The current scene.
   * @var SceneInterface|null
   */
  public ?SceneInterface $currentScene = null {
    get {
      return $this->currentScene;
    }
  }

  /**
   * SceneManager constructor.
   */
  private function __construct(public Game $game {
    get {
      return $this->game;
    }
  })
  {
    $this->scenes = new ItemList(SceneInterface::class);
    $this->eventManager = EventManager::getInstance($this->game);
    $this->saveManager = SaveManager::getInstance($this->game);
  }

  /**
   * Return the instance of the scene manager.
   *
   * @param Game $game The game.
   * @return SceneManager
   */
  public static function getInstance(Game $game): SceneManager
  {
    if (self::$instance === null) {
      self::$instance = new SceneManager($game);
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
    }

    return $this;
  }

  /**
   * Remove scenes from the scene manager.
   *
   * @param SceneInterface ...$scenes The scenes to remove.
   * @return $this The scene manager.
   */
  public function removeScenes(SceneInterface ...$scenes): self
  {
    foreach ($scenes as $scene) {
      $this->scenes->remove($scene);
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
    $this->unloadScene($this->currentScene);
    $this->currentScene = $sceneToLoad;
    if ($this->currentScene?->isStarted()) {
      $this->currentScene?->resume();
    } else {
      $this->currentScene?->start();
    }

    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_END, $this->currentScene));

    return $this;
  }

  /**
   * Unload a scene.
   *
   * @param SceneInterface|null $scene The scene to unload.
   * @return SceneManager The scene manager.
   */
  public function unloadScene(?SceneInterface $scene): self
  {
    if (!$scene) {
      return $this;
    }

    if ($this->scenes->contains($scene)) {
      $scene->stop();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::UNLOAD, $this->currentScene));
    }

    return $this;
  }

  /**
   * Load the game over scene.
   *
   * @return void
   * @throws NotFoundException If the game over scene is not found.
   */
  public function loadGameOverScene(): void
  {
    $this->loadScene(GameOverScene::class);
  }

}