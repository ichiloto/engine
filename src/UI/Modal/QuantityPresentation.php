<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use InvalidArgumentException;

/** A read-only amount snapshot; the QuantitySelector remains the input owner. */
final readonly class QuantityPresentation
{
  public function __construct(public int $minimum, public int $maximum, public int $value)
  {
    if ($minimum < 0 || $maximum < $minimum || $value < $minimum || $value > $maximum) {
      throw new InvalidArgumentException('Quantity presentation requires a value within nonnegative bounds.');
    }
  }
}
