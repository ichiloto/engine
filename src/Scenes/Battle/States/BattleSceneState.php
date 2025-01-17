<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateContextInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use InvalidArgumentException;

/**
 * Represents the battle scene state.
 *
 * @package Ichiloto\Engine\Scenes\Battle\States
 */
abstract class BattleSceneState implements SceneStateInterface, CanResume
{
  public BattleScene $scene {
    get {
      $scene = $this->context->getScene();

      if (! $scene instanceof BattleScene) {
        throw new InvalidArgumentException('Invalid scene type.');
      }

      return $scene;
    }
  }
  /**
   * @var BattleScreen The battle UI.
   */
  public BattleScreen $ui {
    get {
      return $this->scene->ui;
    }
  }
  /**
   * @var BattleEngineInterface The battle engine.
   */
  public BattleEngineInterface $engine {
    get {
      return $this->scene->getGame()->engine;
    }
  }

  /**
   * BattleSceneState constructor.
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
    // Do nothing
  }

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
  public function setState(SceneStateInterface $state): void
  {
    if (! $state instanceof BattleSceneState) {
      throw new InvalidArgumentException('Invalid state type.');
    }

    if (! $this->context instanceof SceneStateContext) {
      throw new InvalidArgumentException('Invalid context type.');
    }

    $scene = $this->context->getScene();

    if (! $scene instanceof BattleScene) {
      throw new InvalidArgumentException('Invalid scene type.');
    }

    $scene->setState($state);
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
}