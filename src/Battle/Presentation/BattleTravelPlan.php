<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;
use LogicException;

/** One immutable, arc-length-parameterized route serves both outbound travel and the backward hop. */
final class BattleTravelPlan
{
  /** @var list<array{float, float}> */
  private readonly array $points;
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
    $copy = [];
    $distance = 0.0;
    foreach ($points as $point) {
      if (!is_array($point) || !array_is_list($point) || count($point) !== 2
        || (!is_float($point[0]) && !is_int($point[0])) || (!is_float($point[1]) && !is_int($point[1]))
        || !is_finite((float)$point[0]) || !is_finite((float)$point[1])) {
        throw new InvalidArgumentException('Battle travel waypoints must be finite coordinate pairs.');
      }
      $last = $copy === [] ? null : $copy[array_key_last($copy)];
      $next = [(float)$point[0], (float)$point[1]];
      if ($last !== null) { $distance += hypot($next[0] - $last[0], $next[1] - $last[1]); }
      $copy[] = $next;
    }
    $this->points = $copy;
    $this->distance = $distance;
  }

  /** Copies protect the route from mutation of the Engine's mutable Vector2 values. @return list<Vector2> */
  public function getWaypoints(): array
  {
    return array_map(static fn(array $point): Vector2 => new Vector2(...$point), $this->points);
  }

  /** Progress measures distance, so speed stays constant through every route segment. Facing never reverses. */
  public function getPositionAtProgress(float $progress, bool $returning = false): Vector2
  {
    if (!$this->isPossible) { throw new LogicException('A blocked battle travel plan has no sampleable route: ' . $this->failure->value); }
    if (!is_finite($progress) || $progress < 0 || $progress > 1) {
      throw new InvalidArgumentException('Battle travel progress must be between zero and one.');
    }
    $progress = $returning ? 1 - $progress : $progress;
    if ($progress === 0.0 || $this->distance === 0.0) { return new Vector2(...$this->points[0]); }
    if ($progress === 1.0) { return new Vector2(...$this->points[array_key_last($this->points)]); }
    $remaining = $progress * $this->distance;
    foreach ($this->points as $index => $point) {
      if ($index === 0) { continue; }
      $previous = $this->points[$index - 1];
      $length = hypot($point[0] - $previous[0], $point[1] - $previous[1]);
      if ($length > 0 && $remaining <= $length) {
        $fraction = $remaining / $length;
        return new Vector2($previous[0] + ($point[0] - $previous[0]) * $fraction,
          $previous[1] + ($point[1] - $previous[1]) * $fraction);
      }
      $remaining -= $length;
    }
    return new Vector2(...$this->points[array_key_last($this->points)]);
  }
}
