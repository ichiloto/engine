<?php

namespace Ichiloto\Engine\Scenes;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\CanActivate;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

class SceneManager implements CanStart, CanActivate, CanResume, CanRender, CanUpdate, SingletonInterface
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

  public function activate(): void
  {
    // TODO: Implement activate() method.
  }

  public function deactivate(): void
  {
    // TODO: Implement deactivate() method.
  }

  public function render(): void
  {
    // TODO: Implement render() method.
  }

  public function erase(): void
  {
    // TODO: Implement erase() method.
  }

  public function resume(): void
  {
    // TODO: Implement resume() method.
  }

  public function suspend(): void
  {
    // TODO: Implement suspend() method.
  }

  public function update(): void
  {
    // TODO: Implement update() method.
  }
}