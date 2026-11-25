<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;

use Assegai\Collections\Queue;
use Ichiloto\Engine\Battle\BattleStateClock;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\EnemyActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnInitState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnResolutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Battle\UI\BattleScreen;

/**
 * Class TurnBasedEngine. A base class for turn-based battle engines.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines
 */
abstract class TurnBasedEngine implements BattleEngineInterface
{
  protected(set) CombatRandomSource $random;
  private ?BattleStateClock $stateClock = null;
  private bool $stateClockSuspended = false;
  /** @var list<CharacterInterface> */
  private array $stateClockBattlers = [];
  /**
   * @var BattleConfig|null The battle configuration.
   */
  protected(set) ?BattleConfig $battleConfig = null;
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
   * @var TurnState|null The turn initialization state.
   */
  protected(set) ?TurnState $turnInitState = null;
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
    protected Game $game,
    ?CombatRandomSource $random = null,
  )
  {
    $this->random = $random ?? new NativeCombatRandomSource();
    $this->turnQueue = new Queue(Turn::class);
    $this->initializeTurnStates();
  }

  /**
   * @inheritDoc
   */
  public function configure(BattleConfig $config): void
  {
    if ($this->battleConfig === $config) { return; }
    $config->bindContent($this->game->getProjectAssetRoot());
    ($this->battleConfig->ui ?? null)?->releaseActionPrefetch();
    $this->battleConfig?->reactions->clear();
    $this->battleConfig?->releaseContent();
    $this->releaseStateClock();
    $this->battleConfig = $config;
    $this->stateClock = new BattleStateClock();
    $this->stateClockBattlers = [...$config->party->members->toArray(), ...$config->troop->members->toArray()];
    foreach ($this->stateClockBattlers as $battler) {
      if (method_exists($battler, 'bindBattleStateClock')) { $battler->bindBattleStateClock($this->stateClock); }
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
   */
  public function stop(): void
  {
    ($this->battleConfig->ui ?? null)?->releaseActionPrefetch();
    if ($this->turnStateExecutionContext !== null) {
      $this->state?->exit($this->turnStateExecutionContext);
    }
    $this->turnQueue->clear();
    $this->releaseStateClock();
    $this->state = null;
    $this->turnStateExecutionContext = null;
    $this->battleConfig?->reactions->clear();
    $this->battleConfig?->releaseContent();
    $this->battleConfig = null;
  }

  /** The configured screen owns the battle from configuration, including a failed start. */
  public function stopForScreen(BattleScreen $ui): void
  {
    if (($this->battleConfig->ui ?? null) === $ui) { $this->stop(); }
  }

  /** Traditional rounds are counted by TurnInitState; ATB supplies its own completion boundary. */
  public function recordTurnCompletion(TurnStateExecutionContext $context, Turn $turn): void {}

  public function handlePartyRosterChange(TurnStateExecutionContext $context): void
  {
    $this->battleConfig?->reactions->responseWindows->releaseInactive([
      ...$context->partyRoster->battlers, ...$context->troop->members->toArray(),
    ]);
    $context->ui->releaseActionPrefetch();
    $this->turnQueue->clear();
    $context->setTurns([]);
    $context->ui->characterNameWindow->setNames(array_map(
      static fn($member): string => $member->name, $context->partyRoster->battlers));
    $context->ui->characterStatusWindow->setCharacters($context->partyRoster->battlers);
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->refreshField();
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
    $this->updateStateClock();
  }

  /** Battle-scene suspension includes Pause and suspended combat event pages. */
  public function setBattleStateClockSuspended(bool $suspended): void
  {
    $this->stateClockSuspended = $suspended;
    $this->updateStateClock();
  }

  /** Advances gameplay and expiry through the same battle-phase boundary. */
  protected function updateBattlePhase(): void
  {
    $this->updateStateClock();
    foreach ($this->stateClockBattlers as $battler) {
      if (method_exists($battler, 'expireElapsedBattleStates')) { $battler->expireElapsedBattleStates(); }
    }
    $this->state->update($this->turnStateExecutionContext);
    $this->updateStateClock();
  }

  private function updateStateClock(): void
  {
    $choosingCommand = $this->state instanceof PlayerActionState && $this->state->getSelectionMode() !== null;
    $this->stateClock?->setRunning($this->state !== null && !$this->stateClockSuspended && !$choosingCommand);
  }

  private function releaseStateClock(): void
  {
    if ($this->stateClock === null) { return; }
    foreach ($this->stateClockBattlers as $battler) {
      if (method_exists($battler, 'releaseBattleStateClock')) { $battler->releaseBattleStateClock($this->stateClock); }
    }
    $this->stateClockBattlers = [];
    $this->stateClock = null;
    $this->stateClockSuspended = false;
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
    if ($this->battleConfig && property_exists($this->battleConfig, 'ui')) {
      $this->battleConfig->ui->refreshField();
    }
  }
}
