<?php

namespace Ichiloto\Engine\Entities\States;

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
  /**
   * @var bool True when the last hit on this battler was critical; the battle UI displays and clears it.
   */
  public bool $lastHitWasCritical = false;

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

    foreach ($elementAffinities as $element => $multiplier) {
      if (is_string($element) && is_numeric($multiplier)) {
        $this->elementAffinities[strtolower(trim($element))] = floatval($multiplier);
      }
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
   * @return bool True when the state was newly inflicted.
   */
  public function addState(State $state, int $chancePercent = 100): bool
  {
    if ($this->hasState($state->id)) {
      return false;
    }

    $resistance = $this->stateResistances[$state->id] ?? 1.0;
    $effectiveChance = intval(round(clamp($chancePercent, 0, 100) * $resistance));

    if ($effectiveChance < 1 || rand(1, 100) > $effectiveChance) {
      return false;
    }

    $this->states[] = new StateInstance($state, $state->durationTurns);

    return true;
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
   * Applies one turn of state ticks: HP deltas and duration expiry.
   *
   * @return array<int, array{state: State, hpDelta: int, expired: bool}> One event per live state, in application order.
   */
  public function tickStates(): array
  {
    $events = [];

    foreach ($this->states as $instance) {
      $hpDelta = 0;

      if ($instance->state->tickFormula !== null && ! $this->isKnockedOut) {
        $target = $this;
        $hpDelta = intval(eval("return {$instance->state->tickFormula};") ?? 0);
        $this->stats->currentHp = max(0, $this->stats->currentHp + $hpDelta);
      }

      $events[] = [
        'state' => $instance->state,
        'hpDelta' => $hpDelta,
        'expired' => $instance->tickDuration(),
      ];
    }

    $this->states = array_values(array_filter(
      $this->states,
      static fn(StateInstance $instance): bool => $instance->remainingTurns !== 0
    ));

    return $events;
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
