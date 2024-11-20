<?php

namespace Ichiloto\Engine\Scenes;

use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateContextInterface;

/**
 * Class SceneStateContext. Represents a scene state context.
 *
 * @package Ichiloto\Engine\Scenes
 */
class SceneStateContext implements SceneStateContextInterface
{
  /**
   * SceneStateContext constructor.
   *
   * @param SceneInterface $scene The scene.
   * @param SceneStateContextInterface|null $previousContext The previous context.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected ?SceneStateContextInterface $previousContext = null,
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function getPreviousContext(): ?SceneStateContextInterface
  {
    return $this->previousContext;
  }

  /**
   * @inheritDoc
   */
  public function getScene(): SceneInterface
  {
    return $this->scene;
  }

  /**
   * @inheritDoc
   */
  public function getSceneManager(): SceneManager
  {
    return SceneManager::getInstance($this->scene->getGame());
  }
}