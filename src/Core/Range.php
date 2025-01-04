<?php

namespace Ichiloto\Engine\Core;

/**
 * Represents a range.
 *
 * @package Ichiloto\Engine\Core
 */
class Range
{
  /**
   * The average of the range.
   *
   * @var int|float
   */
  public int|float $average {
    get {
      return ($this->min + $this->max) / 2;
    }
  }

  /**
   * The difference between the range.
   *
   * @var int|float
   */
  public int|float $difference {
    get {
      return $this->max - $this->min;
    }
  }

  /**
   * A random number within the range.
   *
   * @var int|float
   */
  public int|float $random {
    get {
      return rand($this->min, $this->max);
    }
  }

  /**
   * Creates a new instance of a range.
   *
   * @param int|float $min The minimum value of the range.
   * @param int|float $max The maximum value of the range.
   */
  public function __construct(
    public int|float $min,
    public int|float $max
  )
  {
  }

  /**
   * Determines if a value is within the range.
   *
   * @param int|float $value The value to check.
   * @return bool True if the value is within the range; otherwise, false.
   */
  public function isWithin(int|float $value): bool
  {
    return ! $this->isOutside($value);
  }

  /**
   * Determines if a value is outside the range.
   *
   * @param int|float $value The value to check.
   * @return bool True if the value is outside the range; otherwise, false.
   */
  public function isOutside(int|float $value): bool
  {
    return $value < $this->min || $value > $this->max;
  }
}