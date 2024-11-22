<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateContextInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Debug;

/**
 * Class GameSceneState. Represents a state of the game scene.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
abstract class GameSceneState implements SceneStateInterface
{
  /**
   * GameSceneState constructor.
   *
   * @param SceneStateContextInterface $context
   */
  public function __construct(
    protected(set) SceneStateContextInterface $context
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    // Do nothing.
    Debug::info("Entering " . static::class);
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
    // Do nothing.
    Debug::info("Exiting " . static::class);
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

  /**
   * Quits the game.
   */
  protected function quitGame(): void
  {
    $this->context->getScene()->getGame()->quit();
  }
}