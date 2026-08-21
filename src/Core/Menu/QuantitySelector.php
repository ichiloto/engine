<?php

namespace Ichiloto\Engine\Core\Menu;

use InvalidArgumentException;

/**
 * Shared bounded quantity state for menu workflows.
 *
 * Shops, inventory use, and future stack-based actions should expose the
 * same fine/coarse adjustment contract instead of expanding every possible
 * quantity into a command list.
 */
final class QuantitySelector
{
  /** The currently selected quantity. */
  protected(set) int $quantity;

  /**
   * @param int $maximum Largest selectable quantity.
   * @param int $minimum Smallest selectable quantity.
   * @param int $initial Initially selected quantity.
   * @param int $coarseStep Horizontal-axis adjustment size.
   */
  public function __construct(
    protected(set) int $maximum,
    protected(set) int $minimum = 1,
    int $initial = 1,
    protected(set) int $coarseStep = 10,
  )
  {
    if ($this->minimum < 0) {
      throw new InvalidArgumentException('The minimum quantity cannot be negative.');
    }

    if ($this->maximum < $this->minimum) {
      throw new InvalidArgumentException('The maximum quantity cannot be less than the minimum quantity.');
    }

    if ($this->coarseStep < 1) {
      throw new InvalidArgumentException('The coarse quantity step must be positive.');
    }

    $this->set($initial);
  }

  /** Sets and clamps the selected quantity. */
  public function set(int $quantity): bool
  {
    $next = clamp($quantity, $this->minimum, $this->maximum);
    $changed = ! isset($this->quantity) || $this->quantity !== $next;
    $this->quantity = $next;

    return $changed;
  }

  /** Adjusts the selected quantity and reports whether it changed. */
  public function adjust(int $amount): bool
  {
    return $this->set($this->quantity + $amount);
  }

  /** Applies the standard Shop-style fine and coarse axis controls. */
  public function adjustForAxes(float $vertical, float $horizontal): bool
  {
    $amount = $this->axisAdjustment($vertical, $horizontal);

    return $amount !== 0 && $this->adjust($amount);
  }

  /**
   * Converts menu axes into the engine-wide quantity adjustment contract.
   *
   * Workflows with additional constraints (such as shop funds or inventory
   * capacity) can validate this requested change before applying it, without
   * duplicating the control mapping.
   */
  public function axisAdjustment(float $vertical, float $horizontal): int
  {
    $amount = 0;

    if ($vertical > 0) {
      $amount--;
    } elseif ($vertical < 0) {
      $amount++;
    }

    if ($horizontal > 0) {
      $amount += $this->coarseStep;
    } elseif ($horizontal < 0) {
      $amount -= $this->coarseStep;
    }

    return $amount;
  }
}
