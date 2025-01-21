<?php

namespace Ichiloto\Engine\Entities\Interfaces;

/**
 * Interface CurveGeneratorInterface. Represents a curve generator.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface CurveGeneratorInterface
{
  /**
   * Gets the value.
   *
   * @return int The value.
   */
  public function getValue(?int $level = null): int;

  /**
   * Generates a curve.
   *
   * @return int[] The generated curve.
   */
  public function generateCurve(): array;
}