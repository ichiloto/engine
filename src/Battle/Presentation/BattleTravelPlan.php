<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;
use LogicException;

/** One immutable, arc-length-parameterized route serves both outbound travel and the backward hop. */
final class BattleTravelPlan
{
  private readonly ?BattlePresentationPath $path;
  public readonly float $distance;
  public bool $isPossible { get => $this->failure === null; }
  public float $advanceSeconds { get => $this->distance / $this->advanceSpeed; }
  public float $returnSeconds { get => $this->distance / $this->returnSpeed; }

  /** @param list<array{float, float}> $points Resolved ground-anchor route, not a gameplay path. */
  public function __construct(array $points, public readonly float $contactX, public readonly float $contactY,
    public readonly int $facingSign, public readonly float $advanceSpeed, public readonly float $returnSpeed,
    public readonly ?BattleTravelFailure $failure = null)
  {
    if (!in_array($facingSign, [-1, 1], true) || !is_finite($contactX) || !is_finite($contactY)
      || !is_finite($advanceSpeed) || $advanceSpeed <= 0 || !is_finite($returnSpeed) || $returnSpeed <= 0
      || !array_is_list($points) || ($failure === null ? count($points) < 2 : $points !== [])) {
      throw new InvalidArgumentException('Battle travel requires finite contact, positive speeds and a route or explicit failure.');
    }
    $this->path = $failure === null ? new BattlePresentationPath($points) : null;
    $this->distance = $this->path?->distance ?? 0.0;
  }

  /** Copies protect the route from mutation of the Engine's mutable Vector2 values. @return list<Vector2> */
  public function getWaypoints(): array
  {
    return $this->path?->getWaypoints() ?? [];
  }

  /** Progress measures distance, so speed stays constant through every route segment. Facing never reverses. */
  public function getPositionAtProgress(float $progress, bool $returning = false): Vector2
  {
    if ($this->path === null) { throw new LogicException('A blocked battle travel plan has no sampleable route: ' . $this->failure->value); }
    return $this->path->getPositionAtProgress($returning ? 1 - $progress : $progress);
  }
}
