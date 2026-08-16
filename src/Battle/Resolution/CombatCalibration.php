<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** One provisional calibration boundary for combat arithmetic. */
final class CombatCalibration
{
  public const float DEFENCE_CONSTANT = 240.0;
  public const float MAX_ORDINARY_MITIGATION = 0.75;
  public const float GUARD_FACTOR = 0.5;
  public const int HIT_CHANCE_FLOOR = 5;
  public const int HIT_CHANCE_CEILING = 100;
  public const int CRITICAL_CHANCE_FLOOR = 1;
  public const int CRITICAL_CHANCE_CEILING = 50;
  public const float CRITICAL_MULTIPLIER = 1.5;
  public const int GRACE_CRITICAL_DIVISOR = 10;
  public const float ORDINARY_WARD_FLOOR = 0.25;
  public const float ORDINARY_WEAKNESS_CEILING = 3.0;
  public const int MINIMUM_DAMAGE = 1;
  public const float FUTURE_DRAIN_AGGREGATE_CEILING = 0.25;
}
