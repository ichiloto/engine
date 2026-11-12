<?php

namespace Ichiloto\Engine\Income;

/**
 * Where a completed payment goes.
 *
 * @package Ichiloto\Engine\Income
 */
enum IncomeDelivery: string
{
  /** Straight into party gold. */
  case DEPOSIT = 'deposit';
  /** Held until a `collect_income` script command collects it. */
  case HOLD = 'hold';

  /** @return string[] */
  public static function values(): array
  {
    return array_map(static fn(self $delivery): string => $delivery->value, self::cases());
  }
}
