<?php

namespace Ichiloto\Engine\Scenes\Battle;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleEndState;
use Ichiloto\Engine\Scenes\Battle\States\BattleLoseState;
use Ichiloto\Engine\Scenes\Battle\States\BattlePauseState;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;
use Ichiloto\Engine\Scenes\Battle\States\BattleStartState;
use Ichiloto\Engine\Scenes\Battle\States\BattleWinState;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Override;
use RuntimeException;

/**
 * Represents a battle scene.
 *
 * @package Ichiloto\Engine\Scenes\Battle
 */
class BattleScene extends AbstractScene
{
  /**
   * @var BattleConfig|null The configuration of the scene.
   */
  protected(set) ?BattleConfig $config = null;
  /**
   * @var Party|null The party in the scene.
   */
  public ?Party $party {
    get {
      return $this->config->party ?? null;
    }
  }
  /**
   * @var BattleSceneState|null The state of the scene.
   */
  protected(set) ?BattleSceneState $state = null;
  /**
   * @var SceneStateContext|null The context of the scene state.
   */
  protected(set) ?SceneStateContext $sceneStateContext = null;

  // Battle Scene states
  /**
   * @var BattleEndState|null The end state of the scene.
   */
  protected(set) ?BattleEndState $endState = null;
  /**
   * @var BattleLoseState|null The lose state of the scene.
   */
  protected(set) ?BattleLoseState $loseState = null;
  /**
   * @var BattlePauseState|null The pause state of the scene.
   */
  protected(set) ?BattlePauseState $pauseState = null;
  /**
   * @var BattleRunState|null The run state of the scene.
   */
  protected(set) ?BattleRunState $runState = null;
  /**
   * @var BattleStartState|null The start state of the scene.
   */
  protected(set) ?BattleStartState $startState = null;
  /**
   * @var BattleWinState|null The win state of the scene.
   */
  protected(set) ?BattleWinState $winState = null;
  /**
   * @var BattleScreen|null The battle screen of the scene.
   */
  public ?BattleScreen $ui = null;

  /**
   * Sets the state of the scene.
   *
   * @param BattleSceneState $state The state to set.
   * @return void
   */
  public function setState(BattleSceneState $state): void
  {
    $this->sceneStateContext = new SceneStateContext($this, $this->sceneStateContext);
    $this->state?->exit();
    $this->state = $state;
    $this->state->enter();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function configure(SceneConfigurationInterface $config): void
  {
    if (! $config instanceof BattleConfig) {
      throw new RuntimeException('Invalid configuration type.');
    }

    $this->uiManager->locationHUDWindow->deactivate();
    $this->config = $config;
    $this->initializeBattleSceneStates();
    $this->setState($this->startState);
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    parent::update();
    $this->state->execute($this->sceneStateContext);
  }

  /**
   * Initializes the battle scene states.
   *
   * @return void
   */
  protected function initializeBattleSceneStates(): void
  {
    $this->sceneStateContext = new SceneStateContext($this);
    $this->endState = new BattleEndState($this->sceneStateContext);
    $this->loseState = new BattleLoseState($this->sceneStateContext);
    $this->pauseState = new BattlePauseState($this->sceneStateContext);
    $this->runState = new BattleRunState($this->sceneStateContext);
    $this->startState = new BattleStartState($this->sceneStateContext);
    $this->winState = new BattleWinState($this->sceneStateContext);
  }
}