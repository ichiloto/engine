<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateContextInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;

abstract class GameSceneState implements SceneStateInterface
{
  public function __construct(
    protected SceneStateContextInterface $context
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    // TODO: Implement enter() method.
  }

  /**
   * @inheritDoc
   */
  public abstract function execute(?SceneStateContext $context = null): void;

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // TODO: Implement exit() method.
  }

  /**
   * @inheritDoc
   */
  public function getContext(): SceneStateContextInterface
  {
    return $this->context;
  }

  /**
   * @inheritDoc
   */
  public function setState(SceneStateInterface $state): void
  {
    assert($state instanceof GameSceneState);
    assert($this->context instanceof SceneStateContext);
    $scene = $this->context->getScene();
    assert($scene instanceof GameScene);

    $scene->setState($state);
  }
}