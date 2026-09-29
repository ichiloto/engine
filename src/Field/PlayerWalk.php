<?php

namespace Ichiloto\Engine\Field;

use Closure;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\FieldMetric;

/**
 * RPG Maker MZ's four-directional walking, for input that reports held keys.
 *
 * While a movement action is held the player keeps walking, one committed
 * cell at a time through the ordinary validated step, so collision, gates,
 * triggers, encounters and events behave exactly as a single step does.
 * Steps are paced by the field metric: a step lasts as long as walking
 * speed takes to cover it, so vertical steps take twice as long as sideways
 * ones and both axes cover the field at one apparent speed.
 *
 * - The most recently pressed held direction wins; releasing it falls back
 *   to the next most recent one still held. Opposing directions follow the
 *   same rule.
 * - From standing, a press faces and steps at once. While a step is still
 *   in progress a new direction waits for it to finish: a direction change
 *   grants no free step.
 * - A tap (pressed and released between updates, or during a step) is kept
 *   until the next step, so it is never lost; it cannot outrun walking.
 * - Blocked movement faces the direction and banks no time. After a stall at
 *   most one step catches up.
 * - Any update the field was not in control of (a menu, dialogue, cinematic,
 *   battle or transfer) cancels walking. Keys pressed before or during it
 *   are stale: a direction still held when control returns must be pressed
 *   again.
 *
 * Event-only input (the terminal) never uses this; it keeps stepping once
 * per key event, exactly as before.
 */
final class PlayerWalk
{
  /** Movement actions and their cardinal steps. */
  public const array DIRECTIONS = ['up' => [0, -1], 'down' => [0, 1], 'left' => [-1, 0], 'right' => [1, 0]];
  /** Rounding in summed frame times must never hold a step back a whole update. */
  private const float CLOCK_TOLERANCE_SECONDS = 1e-6;

  /** Seconds until the step in progress has taken its time; zero or less when the next may start. */
  private float $remaining = 0.0;
  /** @var array{action: string, order: int}|null A tap waiting for the next step. */
  private ?array $tap = null;
  /** Presses at or before this order are stale. */
  private int $staleThrough = 0;
  private ?int $lastInputUpdate = null;
  private int $cancellations = 0;

  public function __construct(private readonly FieldMetric $metric = new FieldMetric())
  {
  }

  /**
   * Advance the step clock and commit at most one step.
   *
   * @param float $deltaSeconds Elapsed time since the previous field update.
   * @param Closure(Vector2, float): bool $step Commits one validated step lasting the given seconds; true when the player moved.
   */
  public function update(float $deltaSeconds, Closure $step): void
  {
    $inputUpdate = InputManager::getInputUpdateCount();
    if ($this->lastInputUpdate !== null && $inputUpdate !== $this->lastInputUpdate + 1) {
      $this->cancel();
    }
    $this->lastInputUpdate = $inputUpdate;
    // Only a step in progress runs the clock; standing time owes nothing.
    if ($this->remaining > 0.0) {
      $this->remaining -= is_finite($deltaSeconds) ? max(0.0, $deltaSeconds) : 0.0;
    }

    $action = $this->selectAction();
    if ($action === null) {
      // Standing still banks no time for a later burst.
      $this->remaining = max(0.0, $this->remaining);
      return;
    }
    if ($this->remaining > self::CLOCK_TOLERANCE_SECONDS) {
      return;
    }

    [$x, $y] = self::DIRECTIONS[$action];
    $direction = new Vector2($x, $y);
    $seconds = $this->metric->getWalkSeconds($direction);
    $this->tap = null;
    $cancellations = $this->cancellations;
    $moved = $step($direction, $seconds);
    if ($cancellations !== $this->cancellations) {
      // The step handed control elsewhere (a transfer, a battle): it already cancelled walking.
      return;
    }
    // Carry the overshoot so equal time walks equal distance at any update
    // rate; never carry more than one step's debt.
    $this->remaining = $moved ? max(0.0, $this->remaining + $seconds) : 0.0;
  }

  /** Stop walking and treat every press so far as stale. */
  public function cancel(): void
  {
    $this->remaining = 0.0;
    $this->tap = null;
    $this->staleThrough = InputManager::getLatestPressOrder();
    $this->cancellations++;
  }

  /** The most recently pressed movement action that is held or still waiting as a tap. */
  private function selectAction(): ?string
  {
    $candidates = [];
    foreach (array_keys(self::DIRECTIONS) as $action) {
      $order = InputManager::getButtonPressOrder($action);
      if ($order === null || $order <= $this->staleThrough) {
        continue;
      }
      if (InputManager::isButtonHeld($action)) {
        $candidates[$action] = $order;
      } elseif ($order > ($this->tap['order'] ?? 0)) {
        $this->tap = ['action' => $action, 'order' => $order];
      }
    }
    if ($this->tap !== null) {
      $candidates[$this->tap['action']] = max($candidates[$this->tap['action']] ?? 0, $this->tap['order']);
    }
    if ($candidates === []) {
      return null;
    }
    arsort($candidates);
    return array_key_first($candidates);
  }
}
