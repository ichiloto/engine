<?php

namespace Ichiloto\Engine\Core\Enumerations;

/**
 * An enumeration of the units of time.
 *
 * @package Ichiloto\Engine\Core\Enumerations
 */
enum ChronoUnit
{
  /**
   * Represents a unit of time in nanoseconds.
   */
  case NANOS;
  /**
   * Represents a unit of time in microseconds.
   */
  case MICROS;
  /**
   * Represents a unit of time in milliseconds.
   */
  case MILLIS;
  /**
   * Represents a unit of time in seconds.
   */
  case SECONDS;
  /**
   * Represents a unit of time in minutes.
   */
  case MINUTES;
  /**
   * Represents a unit of time in hours.
   */
  case HOURS;
  /**
   * Represents a unit of time in days.
   */
  case DAYS;
  /**
   * Represents a unit of time in half-days, 12 hours. i.e. AM/PM.
   */
  case HALF_DAYS;
  /**
   * Represents a unit of time in weeks.
   */
  case WEEKS;
  /**
   * Represents a unit of time in months.
   */
  case MONTHS;
  /**
   * Represents a unit of time in years.
   */
  case YEARS;
  /**
   * Represents a unit of time in decades.
   */
  case DECADES;
  /**
   * Represents a unit of time in centuries.
   */
  case CENTURIES;
  /**
   * Represents a unit of time in millennia.
   */
  case MILLENNIA;
  /**
   * Represents a unit of time in eras.
   */
  case ERAS;
  /**
   * Represents a unit of time in forever.
   */
  case FOREVER;
}