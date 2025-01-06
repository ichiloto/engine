<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;

use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Class TurnBasedEngine. A base class for turn-based battle engines.
 */
abstract class TurnBasedEngine implements BattleEngineInterface
{
  protected(set) ?BattleConfig $battleConfig = null;

  public function __construct(
    protected Game $game
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function configure(BattleConfig $config): void
  {
  }

  /**
   * @inheritDoc
   * @throws NotFoundException If the battle scene cannot be found.
   */
  public function start(): void
  {
    // Do nothing. This method is meant to be overridden.
    if ($this->battleConfig) {
      $currentScene = $this->game->sceneManager->loadScene(BattleScene::class)->currentScene;
      $currentScene->configure($this->battleConfig);
    }
  }

  /**
   * @inheritDoc
   * @throws NotFoundException If the game scene cannot be found.
   */
  public function stop(): void
  {
    // Do nothing. This method is meant to be overridden.
    $this->game->sceneManager->loadScene(GameScene::class);
  }
}