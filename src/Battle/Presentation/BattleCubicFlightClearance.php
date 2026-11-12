<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** Preserve the authored curve when possible, with continuous routing through the free arena. */
final class BattleCubicFlightClearance
{
  private const float BEND_RADIUS = 24.0;
  private const int BEND_STEPS = 8;

  /** @param list<CanvasRectangle> $obstacles */
  public static function plan(Vector2 $launch, Vector2 $arrival, Vector2 $first, Vector2 $second,
    int $height, array $obstacles, float $padding, ?int $width = null): ?BattlePresentationPath
  {
    $authored = BattlePresentationPath::fromCubic($launch, $arrival, $first, $second);
    if ($obstacles === []) { return $authored; }

    $top = min($padding, $launch->y, $arrival->y);
    $bottom = max($height - $padding, $launch->y, $arrival->y);
    return self::fitCurve($authored, $launch, $arrival, $first, $second, $top, $bottom, $obstacles, $padding)
      ?? self::routeAroundBodies($launch, $arrival, $top, $bottom, $obstacles, $padding, $width);
  }

  /** @param list<CanvasRectangle> $obstacles */
  private static function fitCurve(BattlePresentationPath $authored, Vector2 $launch, Vector2 $arrival,
    Vector2 $first, Vector2 $second, float $top, float $bottom, array $obstacles, float $padding): ?BattlePresentationPath
  {
    $points = $authored->getWaypoints();
    $samples = [];
    $minimum = -INF;
    $maximum = INF;
    foreach ($points as $index => $point) {
      $t = $index / BattlePresentationPath::CUBIC_STEPS;
      // A common control shift contributes this much vertical motion at t.
      $weight = 3 * $t * (1 - $t);
      $samples[] = [$point->x, $point->y, $weight];
      if ($weight > 0) {
        $minimum = max($minimum, ($top - $point->y) / $weight);
        $maximum = min($maximum, ($bottom - $point->y) / $weight);
      }
    }
    if ($minimum > $maximum) { return null; }
    $forbidden = [];
    foreach ($obstacles as $obstacle) {
      $interval = self::getForbiddenShift($samples, $obstacle, $padding);
      if ($interval !== null) { $forbidden[] = $interval; }
    }

    $preferred = min($maximum, max($minimum, 0));
    $up = $down = $preferred;
    usort($forbidden, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    foreach ($forbidden as [$lower, $upper]) {
      if ($lower < $down && $down < $upper) { $down = $upper; }
    }
    usort($forbidden, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
    foreach ($forbidden as [$lower, $upper]) {
      if ($lower < $up && $up < $upper) { $up = $lower; }
    }
    if ($up < $minimum && $down > $maximum) { return null; }
    $shift = $up >= $minimum && ($down > $maximum || abs($up) <= abs($down)) ? $up : $down;
    return $shift == 0 ? $authored : BattlePresentationPath::fromCubic($launch, $arrival,
      new Vector2($first->x, $first->y + $shift), new Vector2($second->x, $second->y + $shift));
  }

  /** @param list<CanvasRectangle> $obstacles */
  private static function routeAroundBodies(Vector2 $launch, Vector2 $arrival, float $top, float $bottom,
    array $obstacles, float $padding, ?int $width): ?BattlePresentationPath
  {
    $start = [$launch->x, $launch->y];
    $end = [$arrival->x, $arrival->y];
    $left = min($width === null ? $launch->x : $padding, $launch->x, $arrival->x);
    $right = max($width === null ? $launch->x : $width - $padding, $launch->x, $arrival->x);
    $arena = [$left, $top, $right, $bottom];
    $bounds = static fn(float $margin): array => array_map(static fn(CanvasRectangle $body): array => [
      $body->x - $margin, $body->y - $margin, $body->x + $body->width + $margin, $body->y + $body->height + $margin,
    ], $obstacles);
    $physical = $bounds($padding);
    // Look for room to round corners first. A tight corridor still admits an exact clear path.
    $route = BattleRectangleRoute::findRoute($start, $end, $arena, $bounds($padding + self::BEND_RADIUS / 2))
      ?? BattleRectangleRoute::findRoute($start, $end, $arena, $physical);
    if ($route !== null) { return new BattlePresentationPath(self::roundBends($route, $physical)); }
    if (!BattleRectangleRoute::isOccupied($start, $physical)) { return null; }
    $route = BattleRectangleRoute::findRoute($start, $end, $arena, $physical, allowOccludedDeparture: true);
    if ($route === null) { return null; }

    $next = $route[1];
    $dx = $next[0] - $start[0];
    $dy = $next[1] - $start[1];
    $departure = 0.0;
    foreach ($physical as [$left, $top, $right, $bottom]) {
      if (!BattleRectangleRoute::isOccupied($start, [[$left, $top, $right, $bottom]])) { continue; }
      $exitX = $dx === 0.0 ? INF : (($dx < 0 ? $left : $right) - $start[0]) / $dx;
      $exitY = $dy === 0.0 ? INF : (($dy < 0 ? $top : $bottom) - $start[1]) / $dy;
      $departure = max($departure, min($exitX, $exitY));
    }
    return new BattlePresentationPath(self::roundBends($route, $physical), min(1.0, $departure) * hypot($dx, $dy));
  }

  /**
   * @param list<array{float, float}> $route
   * @param list<array{float, float, float, float}> $obstacles
   * @return list<array{float, float}>
   */
  private static function roundBends(array $route, array $obstacles): array
  {
    $points = [$route[0]];
    for ($index = 1, $end = count($route) - 1; $index < $end; $index++) {
      [$previous, $corner, $next] = array_slice($route, $index - 1, 3);
      $incoming = hypot($previous[0] - $corner[0], $previous[1] - $corner[1]);
      $outgoing = hypot($next[0] - $corner[0], $next[1] - $corner[1]);
      $radius = min(self::BEND_RADIUS, $incoming / 2, $outgoing / 2);
      $entry = self::interpolate($corner, $previous, $radius / $incoming);
      $exit = self::interpolate($corner, $next, $radius / $outgoing);
      $bend = [$entry];
      for ($step = 1; $step <= self::BEND_STEPS; $step++) {
        $t = $step / self::BEND_STEPS;
        $point = self::interpolate(self::interpolate($entry, $corner, $t), self::interpolate($corner, $exit, $t), $t);
        if (!BattleRectangleRoute::canJoin($bend[array_key_last($bend)], $point, $obstacles)) {
          $bend = [$corner];
          break;
        }
        $bend[] = $point;
      }
      array_push($points, ...$bend);
    }
    $points[] = $route[array_key_last($route)];
    return $points;
  }

  /** @param array{float, float} $from @param array{float, float} $to @return array{float, float} */
  private static function interpolate(array $from, array $to, float $fraction): array
  {
    return [$from[0] + ($to[0] - $from[0]) * $fraction, $from[1] + ($to[1] - $from[1]) * $fraction];
  }

  /**
   * Along the x-monotone polyline, each obstacle spans one contiguous portion.
   * That portion must stay entirely above or below its padded rectangle.
   * For linear y and weight on a segment, the two shift bounds reach their
   * extrema at the clipped endpoints, including partially crossed segments.
   *
   * @param list<array{float, float, float}> $samples
   * @return ?array{float, float}
   */
  private static function getForbiddenShift(array $samples, CanvasRectangle $obstacle, float $padding): ?array
  {
    $left = $obstacle->x - $padding;
    $right = $obstacle->x + $obstacle->width + $padding;
    $top = $obstacle->y - $padding;
    $bottom = $obstacle->y + $obstacle->height + $padding;
    $above = INF;
    $below = -INF;
    for ($index = 1, $count = count($samples); $index < $count; $index++) {
      [$x0, $y0, $w0] = $samples[$index - 1];
      [$x1, $y1, $w1] = $samples[$index];
      if (max($x0, $x1) < $left || min($x0, $x1) > $right) { continue; }
      $dx = $x1 - $x0;
      if ($dx == 0) {
        $fractions = [0, 1];
      } else {
        $atLeft = ($left - $x0) / $dx;
        $atRight = ($right - $x0) / $dx;
        $fractions = [max(0, min($atLeft, $atRight)), min(1, max($atLeft, $atRight))];
      }
      foreach ($fractions as $fraction) {
        $y = $y0 + ($y1 - $y0) * $fraction;
        $weight = $w0 + ($w1 - $w0) * $fraction;
        $above = min($above, $weight > 0 ? ($top - $y) / $weight : ($y <= $top ? INF : -INF));
        $below = max($below, $weight > 0 ? ($bottom - $y) / $weight : ($y >= $bottom ? -INF : INF));
      }
    }
    return $above < $below ? [$above, $below] : null;
  }
}
