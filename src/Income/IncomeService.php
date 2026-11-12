<?php

namespace Ichiloto\Engine\Income;

use Closure;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\PlayActivity;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Localization\Vocabulary;
use InvalidArgumentException;

/**
 * Runs the project's income schedules for one campaign: counts what each
 * cadence counts while its conditions hold, pays completed periods, holds
 * or deposits the pay, and answers the income script commands.
 *
 * @package Ichiloto\Engine\Income
 */
final class IncomeService
{
  /**
   * @param IncomeCatalog $catalog The project's schedules.
   * @param IncomeLedger $ledger The campaign's saved standing.
   * @param GameState $gameState The world state conditions read.
   * @param Party|null $party The party whose gold receives pay.
   * @param Closure(string, string): void|null $notify Shows a notice as `(title, text)`.
   */
  public function __construct(
    private readonly IncomeCatalog $catalog,
    public readonly IncomeLedger $ledger,
    private readonly GameState $gameState,
    private readonly ?Party $party,
    private readonly ?Closure $notify = null,
  ) {}

  /** Whether the project declares any schedule. */
  public bool $isEnabled {
    get => $this->catalog->getSchedules() !== [];
  }

  /**
   * Counts one frame of play for active-time schedules.
   *
   * @param float $seconds The frame's length; capped at {@see PlayActivity::MAX_FRAME_SECONDS}.
   * @param PlayActivity $activity What the player was doing.
   * @param float $secondsSinceInput Seconds since the player last gave input, at the end of the frame.
   */
  public function advanceActivePlay(float $seconds, PlayActivity $activity, float $secondsSinceInput): void
  {
    if (! is_finite($seconds) || $seconds <= 0.0 || $activity === PlayActivity::PAUSED) {
      return;
    }
    $seconds = min($seconds, PlayActivity::MAX_FRAME_SECONDS);
    foreach ($this->listRunningSchedules(IncomeCadence::ACTIVE_TIME) as $schedule) {
      $counted = $activity === PlayActivity::PRESENTING
        ? $seconds
        // Only the part of the frame before the idle timeout ran out counts.
        : max(0.0, min($seconds, $schedule->idleTimeout - ($secondsSinceInput - $seconds)));
      $this->count($schedule, $counted);
    }
  }

  /** Counts one step the player walked. */
  public function recordStep(): void
  {
    foreach ($this->listRunningSchedules(IncomeCadence::STEPS) as $schedule) {
      $this->count($schedule, 1.0);
    }
  }

  /** Counts one battle the party won. */
  public function recordBattleWon(): void
  {
    foreach ($this->listRunningSchedules(IncomeCadence::BATTLES) as $schedule) {
      $this->count($schedule, 1.0);
    }
  }

  /**
   * Pays one period of a schedule now, at its current rate, without
   * touching what its cadence has counted. Nothing is paid while the
   * schedule's conditions do not hold.
   *
   * @param string $scheduleId The schedule.
   * @return int The pay deposited or held.
   */
  public function payday(string $scheduleId): int
  {
    $schedule = $this->requireSchedule($scheduleId);
    return $schedule->isActive($this->gameState, $this->party) ? $this->pay($schedule, 1) : 0;
  }

  /**
   * Moves a schedule's held pay into party gold. What does not fit under
   * the gold limit stays held. Collection does not depend on the
   * schedule's conditions: earned pay stays collectable.
   *
   * @param string $scheduleId The schedule.
   * @return int The pay collected.
   */
  public function collect(string $scheduleId): int
  {
    $schedule = $this->requireSchedule($scheduleId);
    $account = $this->ledger->getAccount($schedule->id);
    if ($account->held === 0 || $this->party === null) {
      return 0;
    }
    $collected = min($account->held, max(0, Party::MAX_GOLD - $this->party->accountBalance));
    $this->party->credit($collected);
    $account->held -= $collected;
    if ($collected > 0) {
      $this->showNotice($schedule, 'income.collected', $collected, $account->held);
    }
    return $collected;
  }

  /**
   * Adds a one-time bonus (positive) or deduction (negative) to a
   * schedule's next payment. A deduction larger than that payment carries
   * on to the ones after it; a payment never takes gold away.
   *
   * @param string $scheduleId The schedule.
   * @param int $amount The signed adjustment.
   */
  public function adjust(string $scheduleId, int $amount): void
  {
    $this->ledger->getAccount($this->requireSchedule($scheduleId)->id)->adjustment += $amount;
  }

  /** The pay a schedule holds for collection. */
  public function getHeldPay(string $scheduleId): int
  {
    return $this->ledger->getAccount($this->requireSchedule($scheduleId)->id)->held;
  }

  /** @return IncomeSchedule[] The schedules of a cadence whose conditions hold now. */
  private function listRunningSchedules(IncomeCadence $cadence): array
  {
    return array_values(array_filter(
      $this->catalog->getSchedules(),
      fn(IncomeSchedule $schedule): bool => $schedule->cadence === $cadence
        && $schedule->isActive($this->gameState, $this->party),
    ));
  }

  private function count(IncomeSchedule $schedule, float $amount): void
  {
    if ($amount <= 0.0 || $schedule->interval === null) {
      return;
    }
    $account = $this->ledger->getAccount($schedule->id);
    $account->progress += $amount;
    $periods = intdiv((int) floor($account->progress), $schedule->interval);
    if ($periods > 0) {
      $account->progress -= $periods * $schedule->interval;
      $this->pay($schedule, $periods);
    }
  }

  private function pay(IncomeSchedule $schedule, int $periods): int
  {
    $account = $this->ledger->getAccount($schedule->id);
    $total = $periods * $schedule->resolvePeriodPay($this->gameState, $this->party) + $account->adjustment;
    // A deduction larger than this payment stays owed; a payment never debits.
    $account->adjustment = min(0, $total);
    $payout = max(0, $total);
    if ($payout === 0) {
      return 0;
    }

    if ($schedule->delivery === IncomeDelivery::HOLD) {
      $account->held += $payout;
      $this->showNotice($schedule, 'income.held', $payout, $account->held);
      return $payout;
    }

    if ($this->party === null) {
      return 0;
    }
    $before = $this->party->accountBalance;
    $this->party->credit($payout);
    $deposited = $this->party->accountBalance - $before;
    if ($deposited > 0) {
      $this->showNotice($schedule, 'income.paid', $deposited, $account->held);
    }
    return $deposited;
  }

  private function showNotice(IncomeSchedule $schedule, string $term, int $amount, int $held): void
  {
    if ($this->notify === null) {
      return;
    }
    // A blank project message is deliberate silence.
    $message = MessageCatalog::resolveTerm($term, allowEmpty: true);
    if ($message === '') {
      return;
    }
    ($this->notify)($schedule->name, MessageCatalog::format(
      $message, $amount, Vocabulary::getTerm('currency.symbol', 'G'), $schedule->name, $held,
    ));
  }

  private function requireSchedule(string $scheduleId): IncomeSchedule
  {
    return $this->catalog->findSchedule($scheduleId)
      ?? throw new InvalidArgumentException(sprintf('No income schedule has the id "%s".', trim($scheduleId)));
  }
}
