<?php

namespace Ichiloto\Engine\Battle\Engines\ActiveTime;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Engines\ActiveTime\States\ActiveTimeFlowState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnResolutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use RuntimeException;

/**
 * Provides a first wait-mode active-time battle engine.
 *
 * @package Ichiloto\Engine\Battle\Engines\ActiveTime
 */
class ActiveTimeBattleEngine extends TurnBasedEngine
{
  protected const READY_GAUGE = 100.0;
  protected const GAUGE_CAP = 150.0;
  protected const ADVANTAGE_NORMAL = 'normal';
  protected const ADVANTAGE_SURPRISE = 'surprise_attack';
  protected const ADVANTAGE_BACK_ATTACK = 'back_attack';

  /**
   * @var CharacterInterface[] Battlers whose gauges have filled and are ready to act.
   */
  protected array $readyBattlers = [];

  /**
   * @var array<int, float> ATB values keyed by battler object id.
   */
  protected array $gaugeValues = [];

  /**
   * @var array<int, float> Per-battle tie-break seeds keyed by battler object id.
   */
  protected array $initiativeSeeds = [];

  /**
   * @var string The resolved encounter advantage state.
   */
  protected string $encounterAdvantage = self::ADVANTAGE_NORMAL;

  /**
   * @var string|null Pending encounter alert text.
   */
  protected ?string $openingAlertPending = null;

  /**
   * @inheritDoc
   */
  public function configure(BattleConfig $config): void
  {
    if (! $config instanceof ActiveTimeBattleConfig) {
      return;
    }

    $this->battleConfig = $config;
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    if (! $this->battleConfig instanceof ActiveTimeBattleConfig) {
      throw new RuntimeException('The active-time engine requires an active-time battle configuration.');
    }

    $this->initializeTurnStates();
    $this->positionBattlers();
    $this->turnStateExecutionContext = new TurnStateExecutionContext(
      $this->game,
      $this->battleConfig->party,
      $this->battleConfig->troop,
      $this->battleConfig->ui,
      [],
    );
    $this->resetBattleState($this->turnStateExecutionContext);
    $this->refreshStatusWindow($this->turnStateExecutionContext);
    $this->setState($this->turnInitState);
  }

  /**
   * @inheritDoc
   */
  public function run(BattleEngineContextInterface $context): void
  {
    if (Input::isButtonDown('quit')) {
      $this->game->quit();
    }

    if (! $this->turnStateExecutionContext instanceof TurnStateExecutionContext || $this->state === null) {
      return;
    }

    $this->state->update($this->turnStateExecutionContext);
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    parent::stop();
    $this->readyBattlers = [];
    $this->gaugeValues = [];
    $this->initiativeSeeds = [];
    $this->encounterAdvantage = self::ADVANTAGE_NORMAL;
    $this->openingAlertPending = null;
  }

  /**
   * Returns and clears the pending encounter alert.
   *
   * @return string|null
   */
  public function consumeEncounterAlert(): ?string
  {
    $alert = $this->openingAlertPending;
    $this->openingAlertPending = null;

    return $alert;
  }

  /**
   * @inheritDoc
   */
  protected function initializeTurnStates(): void
  {
    $this->actionExecutionState = new ActionExecutionState($this);
    $this->turnInitState = new ActiveTimeFlowState($this);
    $this->turnResolutionState = new TurnResolutionState($this);
    $this->enemyActionState = null;
    $this->playerActionState = null;
  }

  /**
   * Progresses every living battler gauge and enqueues newly ready battlers.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  public function progressGauges(TurnStateExecutionContext $context): void
  {
    $deltaTime = Time::getDeltaTime();

    if ($deltaTime <= 0) {
      $deltaTime = 1 / 60;
    }

    foreach ($this->getBattleParticipants($context) as $battler) {
      $id = spl_object_id($battler);

      if ($battler->isKnockedOut) {
        $this->gaugeValues[$id] = 0.0;
        $this->removeReadyBattler($battler);
        continue;
      }

      if ($this->isBattlerReady($battler)) {
        $this->gaugeValues[$id] = min(
          self::GAUGE_CAP,
          max(self::READY_GAUGE, $this->gaugeValues[$id] ?? self::READY_GAUGE),
        );
        continue;
      }

      $this->gaugeValues[$id] = min(
        self::GAUGE_CAP,
        ($this->gaugeValues[$id] ?? 0.0) + ($this->getFillRate($battler) * $deltaTime),
      );

      if ($this->gaugeValues[$id] >= self::READY_GAUGE) {
        $this->enqueueReadyBattler($battler);
      }
    }
  }

  /**
   * Returns the next battler whose ATB gauge is full.
   *
   * @return CharacterInterface|null
   */
  public function claimNextReadyBattler(): ?CharacterInterface
  {
    $this->readyBattlers = array_values(array_filter(
      $this->readyBattlers,
      fn(CharacterInterface $battler): bool => ! $battler->isKnockedOut
        && ($this->gaugeValues[spl_object_id($battler)] ?? 0.0) >= self::READY_GAUGE,
    ));

    if ($this->readyBattlers === []) {
      return null;
    }

    usort(
      $this->readyBattlers,
      fn(CharacterInterface $left, CharacterInterface $right): int => $this->compareReadyBattlers($left, $right),
    );

    $battler = array_shift($this->readyBattlers);

    return $battler instanceof CharacterInterface ? $battler : null;
  }

  /**
   * Queues and executes one immediate ATB action.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $battler The acting battler.
   * @param BattleAction $action The selected action.
   * @param CharacterInterface[] $targets The resolved targets.
   * @return void
   */
  public function queueImmediateTurn(
    TurnStateExecutionContext $context,
    CharacterInterface $battler,
    BattleAction $action,
    array $targets,
  ): void
  {
    $turn = new Turn($battler);
    $turn->action = $action;
    $turn->targets = $targets;
    $context->setTurns([$turn]);
    $this->resetGauge($battler);
    $this->setState($this->actionExecutionState);
  }

  /**
   * Updates the battle status window to show HP, MP, and ATB.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  public function refreshStatusWindow(TurnStateExecutionContext $context): void
  {
    $context->ui->characterStatusWindow->setAtbPercentages(array_map(
      fn(CharacterInterface $battler): float => $this->getGaugePercentage($battler),
      $context->party->battlers->toArray(),
    ));
  }

  /**
   * Returns the filled percentage for the provided battler gauge.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @return float
   */
  public function getGaugePercentage(CharacterInterface $battler): float
  {
    return clamp(($this->gaugeValues[spl_object_id($battler)] ?? 0.0) / self::READY_GAUGE, 0.0, 1.0);
  }

  /**
   * Returns the effective ATB fill rate for the battler.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @return float
   */
  protected function getFillRate(CharacterInterface $battler): float
  {
    if (! $this->battleConfig instanceof ActiveTimeBattleConfig) {
      return 1.0;
    }

    return max(1.0, $this->battleConfig->baseFillRate + ($this->getBattlerSpeed($battler) * $this->battleConfig->speedFactor));
  }

  /**
   * Returns the current battle participants in draw order.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return CharacterInterface[]
   */
  protected function getBattleParticipants(TurnStateExecutionContext $context): array
  {
    return array_merge(
      $context->party->battlers->toArray(),
      $context->troop->members->toArray(),
    );
  }

  /**
   * Resets gauge state for a newly started battle.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function resetBattleState(TurnStateExecutionContext $context): void
  {
    $this->readyBattlers = [];
    $this->gaugeValues = [];
    $this->initiativeSeeds = [];
    $this->encounterAdvantage = $this->determineEncounterAdvantage();
    $this->openingAlertPending = match ($this->encounterAdvantage) {
      self::ADVANTAGE_SURPRISE => 'Surprise Attack!',
      self::ADVANTAGE_BACK_ATTACK => 'Back Attack!',
      default => null,
    };

    foreach ($this->getBattleParticipants($context) as $battler) {
      $id = spl_object_id($battler);
      $this->initiativeSeeds[$id] = $this->randomFloat(0.0, 1.0);

      if ($battler->isKnockedOut) {
        $this->gaugeValues[$id] = 0.0;
        continue;
      }

      $this->gaugeValues[$id] = $this->resolveInitialGauge($context, $battler);

      if ($this->gaugeValues[$id] >= self::READY_GAUGE) {
        $this->enqueueReadyBattler($battler);
      }
    }
  }

  /**
   * Marks one battler as ready if they are not already queued.
   *
   * @param CharacterInterface $battler The battler to enqueue.
   * @return void
   */
  protected function enqueueReadyBattler(CharacterInterface $battler): void
  {
    if ($this->isBattlerReady($battler)) {
      return;
    }

    $this->readyBattlers[] = $battler;
  }

  /**
   * Removes one battler from the ready queue.
   *
   * @param CharacterInterface $battler The battler to remove.
   * @return void
   */
  protected function removeReadyBattler(CharacterInterface $battler): void
  {
    $this->readyBattlers = array_values(array_filter(
      $this->readyBattlers,
      fn(CharacterInterface $readyBattler): bool => $readyBattler !== $battler,
    ));
  }

  /**
   * Returns whether the battler is already queued as ready.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @return bool
   */
  protected function isBattlerReady(CharacterInterface $battler): bool
  {
    foreach ($this->readyBattlers as $readyBattler) {
      if ($readyBattler === $battler) {
        return true;
      }
    }

    return false;
  }

  /**
   * Resets one battler gauge after acting.
   *
   * @param CharacterInterface $battler The battler whose gauge should reset.
   * @return void
   */
  protected function resetGauge(CharacterInterface $battler): void
  {
    $id = spl_object_id($battler);
    $this->gaugeValues[$id] = 0.0;
    $this->initiativeSeeds[$id] = $this->randomFloat(0.0, 1.0);
    $this->removeReadyBattler($battler);
  }

  /**
   * Resolves the opening ATB gauge for one battler.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $battler The battler to inspect.
   * @return float
   */
  protected function resolveInitialGauge(TurnStateExecutionContext $context, CharacterInterface $battler): float
  {
    if (! $this->battleConfig instanceof ActiveTimeBattleConfig) {
      return 0.0;
    }

    $variance = $this->battleConfig->openingVariance > 0
      ? $this->randomFloat(0.0, $this->battleConfig->openingVariance)
      : 0.0;

    $startingValue = $variance
      + ($this->getBattlerSpeed($battler) * $this->battleConfig->openingSpeedFactor)
      + $this->resolveAdvantageBias($context, $battler);

    return clamp($startingValue, 0.0, self::GAUGE_CAP);
  }

  /**
   * Resolves the opening bias introduced by the encounter advantage state.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $battler The battler to inspect.
   * @return float
   */
  protected function resolveAdvantageBias(TurnStateExecutionContext $context, CharacterInterface $battler): float
  {
    $isPartyBattler = $this->isPartyBattler($context, $battler);

    return match ($this->encounterAdvantage) {
      self::ADVANTAGE_SURPRISE => $isPartyBattler ? 55.0 : -15.0,
      self::ADVANTAGE_BACK_ATTACK => $isPartyBattler ? -15.0 : 55.0,
      default => 0.0,
    };
  }

  /**
   * Determines the encounter advantage state for the battle.
   *
   * @return string
   */
  protected function determineEncounterAdvantage(): string
  {
    if (! $this->battleConfig instanceof ActiveTimeBattleConfig) {
      return self::ADVANTAGE_NORMAL;
    }

    $roll = $this->randomFloat(0.0, 100.0);

    if ($roll < $this->battleConfig->surpriseAttackChancePercent) {
      return self::ADVANTAGE_SURPRISE;
    }

    if ($roll < ($this->battleConfig->surpriseAttackChancePercent + $this->battleConfig->backAttackChancePercent)) {
      return self::ADVANTAGE_BACK_ATTACK;
    }

    return self::ADVANTAGE_NORMAL;
  }

  /**
   * Returns whether the battler belongs to the active party.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $battler The battler to inspect.
   * @return bool
   */
  protected function isPartyBattler(TurnStateExecutionContext $context, CharacterInterface $battler): bool
  {
    return in_array($battler, $context->party->battlers->toArray(), true);
  }

  /**
   * Returns the battler speed used for ATB calculations.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @return int
   */
  protected function getBattlerSpeed(CharacterInterface $battler): int
  {
    return $battler instanceof Character
      ? $battler->effectiveStats->speed
      : $battler->stats->speed;
  }

  /**
   * Compares two ready battlers for ATB dequeue order.
   *
   * @param CharacterInterface $left The left battler.
   * @param CharacterInterface $right The right battler.
   * @return int
   */
  protected function compareReadyBattlers(CharacterInterface $left, CharacterInterface $right): int
  {
    $leftGauge = $this->getReadyOrderingValue($left);
    $rightGauge = $this->getReadyOrderingValue($right);

    if ($leftGauge !== $rightGauge) {
      return $rightGauge <=> $leftGauge;
    }

    $leftSpeed = $this->getBattlerSpeed($left);
    $rightSpeed = $this->getBattlerSpeed($right);

    if ($leftSpeed !== $rightSpeed) {
      return $rightSpeed <=> $leftSpeed;
    }

    $leftSeed = $this->initiativeSeeds[spl_object_id($left)] ?? 0.0;
    $rightSeed = $this->initiativeSeeds[spl_object_id($right)] ?? 0.0;

    if ($leftSeed !== $rightSeed) {
      return $rightSeed <=> $leftSeed;
    }

    return spl_object_id($right) <=> spl_object_id($left);
  }

  /**
   * Returns the numeric ready-ordering value for one battler.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @return float
   */
  protected function getReadyOrderingValue(CharacterInterface $battler): float
  {
    return $this->gaugeValues[spl_object_id($battler)] ?? 0.0;
  }

  /**
   * Returns a random float within the provided range.
   *
   * @param float $minimum The lower bound.
   * @param float $maximum The upper bound.
   * @return float
   */
  protected function randomFloat(float $minimum, float $maximum): float
  {
    if ($maximum <= $minimum) {
      return $minimum;
    }

    return $minimum + ((mt_rand() / mt_getrandmax()) * ($maximum - $minimum));
  }
}