<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** Immutable waypoints sampled at constant distance per unit of progress. */
final readonly class BattlePresentationPath
{
  public const int CUBIC_STEPS = 64;

  /** @var list<array{float, float}> */
  private array $points;
  public float $distance;

  /** @param list<array{float, float}> $points */
  public function __construct(array $points, public float $occludedDepartureDistance = 0.0)
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
    if (!is_finite($occludedDepartureDistance) || $occludedDepartureDistance < 0 || $occludedDepartureDistance > $distance) {
      throw new InvalidArgumentException('Occluded departure distance must be within the presentation path.');
    }
    $this->points = $copy;
    $this->distance = $distance;
  }

  public static function fromCubic(Vector2 $launch, Vector2 $arrival, Vector2 $launchControl, Vector2 $arrivalControl): self
  {
    $points = [];
    // A bounded tessellation keeps flight speed and heading on the same sampled route.
    for ($step = 0; $step <= self::CUBIC_STEPS; $step++) {
      $t = $step / self::CUBIC_STEPS;
      $u = 1 - $t;
      $a = $u ** 3;
      $b = 3 * $u ** 2 * $t;
      $c = 3 * $u * $t ** 2;
      $d = $t ** 3;
      $points[] = [
        $a * $launch->x + $b * $launchControl->x + $c * $arrivalControl->x + $d * $arrival->x,
        $a * $launch->y + $b * $launchControl->y + $c * $arrivalControl->y + $d * $arrival->y,
      ];
    }
    return new self($points);
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
