<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** Immutable waypoints sampled at constant distance per unit of progress. */
final readonly class BattlePresentationPath
{
  /** @var list<array{float, float}> */
  private array $points;
  public float $distance;

  /** @param list<array{float, float}> $points */
  public function __construct(array $points)
  {
    if (!array_is_list($points) || count($points) < 2) {
      throw new InvalidArgumentException('A battle presentation path requires at least two waypoints.');
    }
    $copy = [];
    $distance = 0.0;
    foreach ($points as $point) {
      if (!is_array($point) || !array_is_list($point) || count($point) !== 2
        || (!is_float($point[0]) && !is_int($point[0])) || (!is_float($point[1]) && !is_int($point[1]))
        || !is_finite((float)$point[0]) || !is_finite((float)$point[1])) {
        throw new InvalidArgumentException('Battle presentation waypoints must be finite coordinate pairs.');
      }
      $last = $copy === [] ? null : $copy[array_key_last($copy)];
      $next = [(float)$point[0], (float)$point[1]];
      if ($last !== null) { $distance += hypot($next[0] - $last[0], $next[1] - $last[1]); }
      $copy[] = $next;
    }
    $this->points = $copy;
    $this->distance = $distance;
  }

  /** @return list<Vector2> */
  public function getWaypoints(): array
  {
    return array_map(static fn(array $point): Vector2 => new Vector2(...$point), $this->points);
  }

  public function getPositionAtProgress(float $progress): Vector2
  {
    return $this->getSampleAtProgress($progress)['point'];
  }

  /** @return array{point: Vector2, direction: ?Vector2} */
  public function getSampleAtProgress(float $progress): array
  {
    if (!is_finite($progress) || $progress < 0 || $progress > 1) {
      throw new InvalidArgumentException('Battle presentation path progress must be between zero and one.');
    }
    $remaining = $progress * $this->distance;
    $direction = null;
    foreach ($this->points as $index => $point) {
      if ($index === 0) { continue; }
      $previous = $this->points[$index - 1];
      $dx = $point[0] - $previous[0];
      $dy = $point[1] - $previous[1];
      $length = hypot($dx, $dy);
      if ($length === 0.0) { continue; }
      $direction = new Vector2($dx, $dy);
      if ($progress < 1 && $remaining < $length) {
        $fraction = $remaining / $length;
        return ['point' => new Vector2($previous[0] + $dx * $fraction, $previous[1] + $dy * $fraction),
          'direction' => $direction];
      }
      $remaining -= $length;
    }
    return ['point' => new Vector2(...$this->points[array_key_last($this->points)]), 'direction' => $direction];
  }
}
