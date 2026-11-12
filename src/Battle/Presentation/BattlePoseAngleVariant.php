<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;

/** The main sequence, ground registration and strike reach for one facing-relative angle band. */
final readonly class BattlePoseAngleVariant
{
  public const float MIN_ANGLE = -180.0;
  public const float MAX_ANGLE = 180.0;

  /** @param non-empty-list<int> $frames Source cells on the action atlas. */
  public function __construct(public float $minAngle, public float $maxAngle, public array $frames,
    public BattlePoseReach $reach, public ?PresentationSpritePivot $pivot = null)
  {
    if (!is_finite($minAngle) || !is_finite($maxAngle) || $minAngle < self::MIN_ANGLE || $maxAngle > self::MAX_ANGLE
      || $minAngle >= $maxAngle || !array_is_list($frames) || $frames === [] || count($frames) > 10000
      || array_any($frames, static fn($frame): bool => !is_int($frame) || $frame < 0)) {
      throw new InvalidArgumentException('An arrival pose needs an increasing angle band within -180..180 and valid atlas cells.');
    }
  }
}
