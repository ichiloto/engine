<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Continuous point routes through a bounded field of rectangular obstacles. */
final class BattleRectangleRoute
{
  public const int MAX_OBSTACLES = 64;
  private const float EPSILON = 0.0000001;

  /**
   * @param array{float, float} $start
   * @param array{float, float} $end
   * @param array{float, float, float, float} $allowed
   * @param list<array{float, float, float, float}> $obstacles
   * @return list<array{float, float}>|null
   */
  public static function findRoute(array $start, array $end, array $allowed, array $obstacles,
    bool $allowOccludedDeparture = false): ?array
  {
    if (count($obstacles) > self::MAX_OBSTACLES
      || !self::contains($allowed, $start) || !self::contains($allowed, $end)
      || (!$allowOccludedDeparture && self::isOccupied($start, $obstacles)) || self::isOccupied($end, $obstacles)) {
      return null;
    }
    // An attached visual effect can leave a body that covers its origin. Only its first segment can pass through it.
    $departureObstacles = $allowOccludedDeparture
      ? array_filter($obstacles, static fn(array $box): bool => !self::isOccupied($start, [$box])) : $obstacles;
    if (self::canJoin($start, $end, $departureObstacles)) {
      return [$start, $end];
    }
    $points = [$start, $end];
    // Axis-aligned unions turn only at rectangle corners. Clamped corners also expose arena intersections.
    // Keep the original obstacles for collision: clipping them would create a false passage along arena edges.
    foreach ([...$obstacles, $allowed] as [$left, $top, $right, $bottom]) {
      foreach ([[$left, $top], [$left, $bottom], [$right, $top], [$right, $bottom]] as [$x, $y]) {
        $point = [max($allowed[0], min($allowed[2], $x)), max($allowed[1], min($allowed[3], $y))];
        if (!self::isOccupied($point, $obstacles) && !in_array($point, $points, true)) { $points[] = $point; }
      }
    }
    // Dijkstra over a small implicit visibility graph: O(V² * obstacles), O(V) storage.
    $distances = array_fill(0, count($points), INF);
    $previous = $visited = [];
    $distances[0] = 0.0;
    while (true) {
      $current = null;
      $best = INF;
      foreach ($distances as $index => $distance) {
        if (!isset($visited[$index]) && $distance < $best) { $current = $index; $best = $distance; }
      }
      if ($current === null) { return null; }
      if ($current === 1) { break; }
      $visited[$current] = true;
      foreach ($points as $next => $point) {
        if (isset($visited[$next])) { continue; }
        $distance = $best + hypot($point[0] - $points[$current][0], $point[1] - $points[$current][1]);
        if ($distance < $distances[$next] && self::canJoin($points[$current], $point,
          $current === 0 ? $departureObstacles : $obstacles)) {
          $distances[$next] = $distance;
          $previous[$next] = $current;
        }
      }
    }
    $route = [];
    for ($index = 1; ; $index = $previous[$index]) {
      $route[] = $points[$index];
      if ($index === 0) { break; }
    }
    return array_reverse($route);
  }

  /** @param array{float, float, float, float} $box @param array{float, float} $point */
  public static function contains(array $box, array $point): bool
  {
    return $box[0] <= $box[2] && $box[1] <= $box[3]
      && $point[0] >= $box[0] - self::EPSILON && $point[0] <= $box[2] + self::EPSILON
      && $point[1] >= $box[1] - self::EPSILON && $point[1] <= $box[3] + self::EPSILON;
  }

  /** @param array{float, float} $point @param list<array{float, float, float, float}> $boxes */
  public static function isOccupied(array $point, array $boxes): bool
  {
    return array_any($boxes, static fn(array $box): bool => $point[0] > $box[0] + self::EPSILON
      && $point[0] < $box[2] - self::EPSILON && $point[1] > $box[1] + self::EPSILON && $point[1] < $box[3] - self::EPSILON);
  }

  /** Exact open-interior segment/slab intersection prevents tunnelling between sampled frames.
   * @param array{float, float} $from @param array{float, float} $to
   * @param list<array{float, float, float, float}> $boxes
   */
  public static function canJoin(array $from, array $to, array $boxes): bool
  {
    foreach ($boxes as $box) {
      $enter = 0.0;
      $leave = 1.0;
      foreach ([0, 1] as $axis) {
        $delta = $to[$axis] - $from[$axis];
        if (abs($delta) <= self::EPSILON) {
          if ($from[$axis] <= $box[$axis] + self::EPSILON || $from[$axis] >= $box[$axis + 2] - self::EPSILON) { continue 2; }
        } else {
          $one = ($box[$axis] - $from[$axis]) / $delta;
          $two = ($box[$axis + 2] - $from[$axis]) / $delta;
          $enter = max($enter, min($one, $two));
          $leave = min($leave, max($one, $two));
        }
      }
      if ($leave - $enter > self::EPSILON) { return false; }
    }
    return true;
  }
}
