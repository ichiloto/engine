<?php

namespace Ichiloto\Engine\Income;

/**
 * One row of a schedule's pay table: the pay per period while its world
 * conditions hold.
 *
 * @package Ichiloto\Engine\Income
 */
final readonly class IncomeRate
{
  /**
   * @param array<int, array<string, mixed>> $conditions Shared world conditions.
   * @param int $amount The pay per period.
   */
  public function __construct(
    public array $conditions,
    public int $amount,
  ) {}
}
