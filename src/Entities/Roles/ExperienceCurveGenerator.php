<?php

namespace Ichiloto\Engine\Entities\Roles;

use Ichiloto\Engine\Entities\Interfaces\CurveGeneratorInterface;

/**
 * Class ExperienceCurveGenerator. This class is responsible for generating the experience curve values.
 *
 * @package Ichiloto\Engine\Entities\Roles
 */
readonly class ExperienceCurveGenerator implements CurveGeneratorInterface
{
  /**
   * @var int[] The curve.
   */
  protected(set) array $curve;

  /**
   * ExperienceCurveGenerator constructor.
   *
   * @param int $baseValue The base value.
   * @param int $extraValue The extra value.
   * @param int $accelerationA The acceleration A.
   * @param int $accelerationB The acceleration B.
   */
  public function __construct(
    public int $baseValue = 30,
    public int $extraValue = 20,
    public int $accelerationA = 30,
    public int $accelerationB = 30,
  )
  {
    $this->curve = $this->generateCurve();
  }

  /**
   * @inheritDoc
   */
  public function getValue(?int $level = null): int
  {
    if (!$level) {
      return 0;
    }

    return $this->curve[$level] ?? 0;
  }

  /**
   * @inheritDoc
   */
  public function generateCurve(): array
  {
    return generate_experience_curve($this->baseValue, $this->extraValue, $this->accelerationA, $this->accelerationB);
  }
}