<?php

namespace Ichiloto\Engine\Scenes;

use Assegai\Collections\ItemList;
use Exception;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
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
   * The scenes in the scene manager.
   * @var ItemList<SceneInterface>
   */
  protected ItemList $scenes;

  /**
   * SceneManager constructor.
   */
  private function __construct()
  {
    $this->scenes = new ItemList(SceneInterface::class);
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
    }

    return $this;
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
  public function render(): void
  {
    // TODO: Implement render() method.
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    // TODO: Implement erase() method.
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // TODO: Implement update() method.
  }

  /**
   * Load a scene.
   *
   * @param string|int $index The index of the scene to load.
   * @throws Exception If the scene is not found.
   */
  public function loadScene(string|int $index): void
  {
    $sceneToLoad = match(true) {
      is_int($index) => $this->scenes->toArray()[$index] ?? throw new NotFoundException('Scene not found.'),
      default => $this->scenes->find(fn(SceneInterface $scene) => $scene::class === $index) ?? throw new NotFoundException('Scene not found.'),
    };
  }

  /**
   * Load the game over scene.
   */
  public function loadGameOverScene(): void
  {
    // TODO: Implement loadGameOverScene() method.
    throw new Exception('Method not implemented.');
  }
}