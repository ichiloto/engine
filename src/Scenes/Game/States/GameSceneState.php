<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateContextInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/**
 * Class GameSceneState. Represents a state of the game scene.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
abstract class GameSceneState implements SceneStateInterface, CanResume
{
  /**
   * @var Party The party.
   */
  public Party $party {
    get {
      return $this->getGameScene()->party;
    }
  }

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
    // Do nothing. The default implementation is to do nothing.
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    // Do nothing. The default implementation is to do nothing.
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    // Do nothing. The default implementation is to do nothing.
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
   * Returns the game scene.
   *
   * @return GameScene The game scene.
   */
  public function getGameScene(): GameScene
  {
    $scene = $this->context->getScene();
    if (! $scene instanceof GameScene) {
      Debug::error("The scene {$scene->name} is not an instance of GameScene.");
      throw new RuntimeException('The scene is not an instance of GameScene.');
    }

    return $scene;
  }

  /**
   * Quits the game.
   */
  protected function quitGame(): void
  {
    $this->context->getScene()->getGame()->quit();
  }
}