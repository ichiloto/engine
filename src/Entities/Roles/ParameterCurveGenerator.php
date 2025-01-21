<?php

namespace Ichiloto\Engine\Entities\Roles;

use Ichiloto\Engine\Entities\Interfaces\CurveGeneratorInterface;

/**
 * Class ParameterCurve. This class is responsible for generating the parameter curve values.
 *
 * @package Ichiloto\Engine\Entities\Roles
 */
readonly class ParameterCurveGenerator implements CurveGeneratorInterface
{
  protected(set) array $curve;

  /**
   * ParameterCurve constructor.
   *
   * @param int $level The level.
   * @param int $baseValue The base value.
   * @param int $extraGrowth The extra growth.
   * @param int $flatIncrement The flat increment.
   */
  public function __construct(
    public int $level,
    public int $baseValue,
    public int $extraGrowth = 50,
    public int $flatIncrement = 1,
  )
  {
    $this->curve = $this->generateCurve();
  }

  /**
   * @inheritDoc
   */
  public function getValue(?int $level = null): int
  {
    return $this->curve[$level ?? $this->level] ?? 0;
  }

  /**
   * @inheritDoc
   */
  public function generateCurve(): array
  {
    return generate_parameter_curve($this->baseValue, $this->extraGrowth, $this->flatIncrement);
  }
}