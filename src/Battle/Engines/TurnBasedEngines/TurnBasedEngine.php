<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;

use Assegai\Collections\Queue;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\EnemyActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnInitState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnResolutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Class TurnBasedEngine. A base class for turn-based battle engines.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines
 */
abstract class TurnBasedEngine implements BattleEngineInterface
{
  /**
   * @var TurnBasedBattleConfig|null The battle configuration.
   */
  protected(set) ?TurnBasedBattleConfig $battleConfig = null;
  /**
   * @var TurnState|null The state of the engine.
   */
  protected(set) ?TurnState $state = null;
  /**
   * @var Queue<Turn> The turn queue.
   */
  protected(set) Queue $turnQueue;
  /**
   * @var TurnStateExecutionContext|null The turn state execution context.
   */
  protected ?TurnStateExecutionContext $turnStateExecutionContext = null;
  /**
   * @var ActionExecutionState|null The action execution state.
   */
  protected(set) ?ActionExecutionState $actionExecutionState = null;
  /**
   * @var EnemyActionState|null The enemy action state.
   */
  protected(set) ?EnemyActionState $enemyActionState = null;
  /**
   * @var PlayerActionState|null The player action state.
   */
  protected(set) ?PlayerActionState $playerActionState = null;
  /**
   * @var TurnInitState|null The turn initialization state.
   */
  protected(set) ?TurnInitState $turnInitState = null;
  /**
   * @var TurnResolutionState|null The turn resolution state.
   */
  protected(set) ?TurnResolutionState $turnResolutionState = null;

  /**
   * TurnBasedEngine constructor.
   *
   * @param Game $game
   */
  public function __construct(
    protected Game $game
  )
  {
    $this->turnQueue = new Queue(CharacterInterface::class);
    $this->initializeTurnStates();
  }

  /**
   * @inheritDoc
   */
  public function configure(BattleConfig $config): void
  {
    if ($config instanceof TurnBasedBattleConfig) {
      $this->battleConfig = $config;
    }
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    // Do nothing. This method should be overridden by the child class.
  }

  /**
   * @inheritDoc
   * @throws NotFoundException If the game scene cannot be found.
   */
  public function stop(): void
  {
    $this->game->sceneManager->loadScene(GameScene::class);
  }

  /**
   * Sets the state of the engine.
   *
   * @param TurnState $state
   * @return void
   */
  public function setState(TurnState $state): void
  {
    $this->state?->exit($this->turnStateExecutionContext);
    $this->state = $state;
    $this->state->enter($this->turnStateExecutionContext);
  }

  /**
   * Initializes the turn states.
   *
   * @return void
   */
  protected function initializeTurnStates(): void
  {
    $this->actionExecutionState = new ActionExecutionState($this);
    $this->enemyActionState = new EnemyActionState($this);
    $this->playerActionState = new PlayerActionState($this);
    $this->turnInitState = new TurnInitState($this);
    $this->turnResolutionState = new TurnResolutionState($this);
  }

  /**
   * Positions the battlers.
   *
   * @return void
   */
  protected function positionBattlers(): void
  {
    $this->battleConfig->ui->fieldWindow->renderParty($this->battleConfig->party);
    $this->battleConfig->ui->fieldWindow->renderTroop($this->battleConfig->troop);
  }
}