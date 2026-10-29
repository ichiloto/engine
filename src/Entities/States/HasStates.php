<?php

namespace Ichiloto\Engine\Entities\States;

use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;

/**
 * Gives a battler a live state list with resistances, ticks, and expiry.
 *
 * @package Ichiloto\Engine\Entities\States
 */
trait HasStates
{
  /**
   * @var StateInstance[] The battler's live states.
   */
  protected(set) array $states = [];
  /**
   * @var bool True while the battler is guarding (halves incoming damage until their next turn).
   */
  protected(set) bool $isGuarding = false;
  /**
   * @var array<string, float> Per-state infliction multipliers, keyed by state id. 0 grants immunity; values above 1 mark vulnerability.
   */
  protected(set) array $stateResistances = [];
  /**
   * @var array<string, float> Elemental damage multipliers, keyed by lowercase element name. 2.0 weak, 0.5 resist, 0.0 null, negative absorbs.
   */
  protected(set) array $elementAffinities = [];
  /**
   * @var string|null The last elemental reaction tag (WEAK!, RESIST, NULL, ABSORB) for the battle UI to display and clear.
   */
  public ?string $lastElementReaction = null;
  /** Current state-owned ATB pace; movement and ordinary stats keep their own owners. */
  public function getAtbRate(): float
  {
    $rate = 1.0;
    foreach ($this->states as $instance) {
      if ($instance->remainingTurns !== 0) { $rate *= $instance->state->atbRate; }
    }
    return $rate;
  }
  /**
   * @var bool True when the last hit on this battler was critical; the battle UI displays and clears it.
   */
  public bool $lastHitWasCritical = false;

  /** State definitions are shared resources; live duration belongs to each battler. */
  protected function cloneStateInstances(): void
  {
    $this->states = array_map(static fn(StateInstance $instance): StateInstance => clone $instance, $this->states);
  }

  /**
   * Sets the battler's state resistances.
   *
   * @param array<string, float> $stateResistances Multipliers keyed by state id.
   * @return void
   */
  public function setStateResistances(array $stateResistances): void
  {
    $this->stateResistances = [];

    foreach ($stateResistances as $stateId => $multiplier) {
      if (is_string($stateId) && is_numeric($multiplier)) {
        $this->stateResistances[trim($stateId)] = max(0.0, floatval($multiplier));
      }
    }
  }

  /**
   * Sets the battler's elemental affinities.
   *
   * @param array<string, float> $elementAffinities Multipliers keyed by element name.
   * @return void
   */
  public function setElementAffinities(array $elementAffinities): void
  {
    $this->elementAffinities = [];

    foreach (ElementRegistry::normalizeAffinities($elementAffinities) as $element => $multiplier) {
      $this->elementAffinities[strtolower($element)] = $multiplier;
    }
  }

  /**
   * Returns the damage multiplier this battler applies to an element.
   *
   * @param string|null $element The element name; null is always neutral.
   * @return float The multiplier (1.0 when unlisted).
   */
  /**
   * Returns the element this battler's basic attacks carry.
   *
   * @return string|null The element, or null for a neutral attack.
   */
  public function getAttackElement(): ?string
  {
    return null;
  }

  public function getElementMultiplier(?string $element): float
  {
    if ($element === null || trim($element) === '') {
      return 1.0;
    }

    return $this->elementAffinities[strtolower(trim($element))] ?? 1.0;
  }

  /**
   * Attempts to inflict a state on this battler.
   *
   * @param State $state The state to add.
   * @param int $chancePercent The base infliction chance (before resistance).
   * @param bool $givenByBearer Defers the first tick when a state is received during the bearer's current turn, including its own effects and interrupting responses.
   * @param bool $guaranteed True when an authored effect guarantees application, bypassing resistance and the chance roll.
   * @return bool True when the state was newly inflicted.
   */
  public function addState(
    State $state,
    int $chancePercent = 100,
    ?CombatRandomSource $random = null,
    bool $givenByBearer = false,
    bool $guaranteed = false,
  ): bool
  {
    $effectiveChance = $this->getStateApplicationChance($state, $chancePercent, $guaranteed);
    if ($effectiveChance < 1) { return false; }

    $random ??= new NativeCombatRandomSource();

    if (!$guaranteed && $random->nextInt(1, 100) > $effectiveChance) {
      return false;
    }

    $this->states[] = new StateInstance($state, $state->durationTurns, awaitsNextTurn: $givenByBearer);

    return true;
  }

  /** Available application chance without rolling or changing live state; shared by effects and menu previews. */
  public function getStateApplicationChance(State $state, int $chancePercent = 100, bool $guaranteed = false): int
  {
    if ($this->hasState($state->id)) { return 0; }
    return $guaranteed ? 100 : intval(round(clamp($chancePercent, 0, 100) * ($this->stateResistances[$state->id] ?? 1.0)));
  }

  /**
   * Removes a state by id.
   *
   * @param string $stateId The state id.
   * @return bool True when the state was present.
   */
  public function removeState(string $stateId): bool
  {
    $before = count($this->states);
    $this->states = array_values(array_filter(
      $this->states,
      static fn(StateInstance $instance): bool => $instance->state->id !== trim($stateId)
    ));

    return count($this->states) < $before;
  }

  /**
   * Determines whether the battler is afflicted with a state.
   *
   * @param string $stateId The state id.
   * @return bool True when afflicted.
   */
  public function hasState(string $stateId): bool
  {
    foreach ($this->states as $instance) {
      if ($instance->state->id === trim($stateId)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Determines whether an afflicting state prevents the battler from acting.
   *
   * @return State|null The blocking state, or null when the battler can act.
   */
  public function getActionBlockingState(): ?State
  {
    foreach ($this->states as $instance) {
      if ($instance->state->preventsAction) {
        return $instance->state;
      }
    }

    return null;
  }

  /**
   * Returns how much of a blockable hit this battler takes: every protective state's rate multiplied together.
   *
   * @return float 1.0 unprotected, 0.5 halved, 0.0 blocked outright.
   */
  public function getDamageTakenRate(): float
  {
    $rate = 1.0;
    foreach ($this->states as $instance) {
      $rate *= $instance->state->damageTakenRate;
    }

    return $rate;
  }

  /**
   * Determines whether a state has this battler taking the hits meant for its weakest ally.
   *
   * @return bool True while the battler covers its allies.
   */
  public function isCoveringAllies(): bool
  {
    foreach ($this->states as $instance) {
      if ($instance->state->coversAllies) {
        return true;
      }
    }

    return false;
  }

  /**
   * Returns the chance that this battler acts confused on its turn: the highest of its states' confusion chances.
   *
   * @return int 0 to 100; 0 when no state confuses it.
   */
  public function getConfusionChancePercent(): int
  {
    $chance = 0;
    foreach ($this->states as $instance) {
      $chance = max($chance, $instance->state->confusionChancePercent);
    }

    return $chance;
  }

  /** Harmful or action-blocking states take precedence over beneficial states. */
  public function getStateDisposition(): StateDisposition
  {
    $disposition = StateDisposition::NEUTRAL;
    foreach ($this->states as $instance) {
      if ($instance->state->preventsAction || $instance->state->disposition === StateDisposition::HARMFUL) {
        return StateDisposition::HARMFUL;
      }
      if ($instance->state->disposition === StateDisposition::BENEFICIAL) {
        $disposition = StateDisposition::BENEFICIAL;
      }
    }
    return $disposition;
  }

  /**
   * Applies one turn of state ticks: HP deltas and duration expiry.
   *
   * @return array<int, array{state: State, hpDelta: int, expired: bool}> One event per live state, in application order.
   */
  public function tickStates(): array
  {
    $events = [];

    foreach ($this->states as $instance) {
      // A state the bearer gave itself this turn starts counting after it.
      if ($instance->awaitsNextTurn) {
        $instance->awaitsNextTurn = false;
        $events[] = ['state' => $instance->state, 'hpDelta' => 0, 'expired' => false];
        continue;
      }

      $hpDelta = 0;

      if ($instance->state->tickFormula !== null && ! $this->isKnockedOut) {
        $before = $this->stats->currentHp;
        $hpDelta = $this->evaluateTickFormula($instance->state);
        $floor = min($before, max(0, $this->knockoutFloor));

        // An authored knockout floor holds damaging ticks above it, like any other battle damage.
        if ($floor > 0 && $before + $hpDelta < $floor) {
          $hpDelta = $floor - $before;
        }

        $this->stats->currentHp = max(0, $before + $hpDelta);
      }

      $events[] = [
        'state' => $instance->state,
        'hpDelta' => $hpDelta,
        'expired' => $instance->tickDuration(),
      ];
    }

    $this->removeExpiredStates();

    return $events;
  }

  /**
   * Counts one step walked in the field. Each state whose steps make up a
   * field turn acts as it does on a battle turn: its tick formula applies and
   * its duration advances. Field ticks stop at 1 HP unless the state is
   * authored to knock out. A knocked-out bearer's states wait.
   *
   * @return array<int, array{state: State, hpDelta: int, expired: bool}> One event per state that acted, with the HP actually changed.
   */
  public function tickFieldStep(): array
  {
    if ($this->isKnockedOut) {
      return [];
    }

    $events = [];

    foreach ($this->states as $instance) {
      if (! $instance->advanceFieldStep()) {
        continue;
      }

      $hpDelta = 0;

      if ($instance->state->tickFormula !== null && ! $this->isKnockedOut) {
        $before = $this->stats->currentHp;
        $floor = $instance->state->fieldTickKnocksOut ? 0 : min(1, $before);
        $this->stats->currentHp = max($floor, $before + $this->evaluateTickFormula($instance->state));
        $hpDelta = $this->stats->currentHp - $before;
      }

      $events[] = [
        'state' => $instance->state,
        'hpDelta' => $hpDelta,
        'expired' => $instance->tickDuration(),
      ];
    }

    $this->removeExpiredStates();

    return $events;
  }

  /**
   * Cures every state authored as cured by rest (an inn stay or a full party recovery).
   *
   * @return State[] The states cured.
   */
  public function removeRestCurableStates(): array
  {
    $cured = [];
    $this->states = array_values(array_filter(
      $this->states,
      static function (StateInstance $instance) use (&$cured): bool {
        if (! $instance->state->curedByRest) {
          return true;
        }

        $cured[] = $instance->state;
        return false;
      }
    ));

    return $cured;
  }

  /**
   * Evaluates a state's authored tick formula against this bearer.
   *
   * A healing tick (a positive delta) stops at the bearer's maximum HP, as any other recovery does.
   *
   * @param State $state The state.
   * @return int The HP delta the tick applies (negative harms).
   */
  protected function evaluateTickFormula(State $state): int
  {
    $target = $this;
    $delta = intval(eval("return {$state->tickFormula};") ?? 0);

    return $delta > 0 ? min($delta, max(0, $this->stats->totalHp - $this->stats->currentHp)) : $delta;
  }

  /** Drops the states whose duration has run out. */
  protected function removeExpiredStates(): void
  {
    $this->states = array_values(array_filter(
      $this->states,
      static fn(StateInstance $instance): bool => $instance->remainingTurns !== 0
    ));
  }

  /**
   * Starts guarding: incoming damage is halved until the guard is dropped.
   *
   * @return void
   */
  public function beginGuarding(): void
  {
    $this->isGuarding = true;
  }

  /**
   * Drops the guard (the battler's next turn has come around).
   *
   * @return void
   */
  public function stopGuarding(): void
  {
    $this->isGuarding = false;
  }

  /**
   * Removes every state that does not persist beyond battle.
   *
   * @return void
   */
  public function clearBattleStates(): void
  {
    $this->isGuarding = false;
    $this->states = array_values(array_filter(
      $this->states,
      static fn(StateInstance $instance): bool => $instance->state->persistsAfterBattle
    ));
  }
}
