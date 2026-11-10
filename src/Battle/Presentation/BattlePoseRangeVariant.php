<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;

/** An alternate main sequence and its matching contact at a longer approach distance. */
final readonly class BattlePoseRangeVariant
{
  /** @param non-empty-list<int> $frames Source cells on the same registered atlas. */
  public function __construct(public float $minDistance, public array $frames, public BattlePoseReach $reach,
    public ?PresentationSpritePivot $pivot = null)
  {
    if (!is_finite($minDistance) || $minDistance <= 0 || $minDistance > 1
      || !array_is_list($frames) || $frames === [] || count($frames) > 10000
      || array_any($frames, static fn($frame): bool => !is_int($frame) || $frame < 0)) {
      throw new InvalidArgumentException('A ranged action pose needs a normalized minimum distance and valid atlas cells.');
    }
  }
}
