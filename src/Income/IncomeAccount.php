<?php

namespace Ichiloto\Engine\Income;

/**
 * One schedule's saved standing: progress towards the next period, pay held
 * for collection, and the one-time adjustment owed to the next payment.
 *
 * @package Ichiloto\Engine\Income
 */
final class IncomeAccount
{
  /**
   * @param float $progress Seconds, steps or won battles counted towards the next period.
   * @param int $held Pay waiting to be collected.
   * @param int $adjustment Signed bonus or deduction folded into the next payment.
   */
  public function __construct(
    public float $progress = 0.0,
    public int $held = 0,
    public int $adjustment = 0,
  ) {}

  /** Whether nothing is owed, held or counted, so the save need not keep it. */
  public bool $isEmpty {
    get => $this->progress === 0.0 && $this->held === 0 && $this->adjustment === 0;
  }

  /** @return array{progress: float, held: int, adjustment: int} */
  public function toArray(): array
  {
    return ['progress' => $this->progress, 'held' => $this->held, 'adjustment' => $this->adjustment];
  }

  /**
   * Restores a saved account; malformed values never grant pay.
   *
   * @param mixed $data The saved account.
   * @return self The account.
   */
  public static function fromArray(mixed $data): self
  {
    $data = is_array($data) ? $data : [];
    $progress = $data['progress'] ?? 0;
    $held = $data['held'] ?? 0;
    $adjustment = $data['adjustment'] ?? 0;

    return new self(
      (is_int($progress) || is_float($progress)) && is_finite((float) $progress) ? max(0.0, (float) $progress) : 0.0,
      is_int($held) ? max(0, $held) : 0,
      is_int($adjustment) ? $adjustment : 0,
    );
  }
}
