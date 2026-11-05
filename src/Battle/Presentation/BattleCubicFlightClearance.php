<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** Find the smallest vertical change to both controls that clears all drawn bodies. */
final class BattleCubicFlightClearance
{
  /** @param list<CanvasRectangle> $obstacles */
  public static function plan(Vector2 $launch, Vector2 $arrival, Vector2 $first, Vector2 $second,
    int $height, array $obstacles, float $padding): ?BattlePresentationPath
  {
    $authored = BattlePresentationPath::fromCubic($launch, $arrival, $first, $second);
    if ($obstacles === []) { return $authored; }

    $points = $authored->getWaypoints();
    $samples = [];
    $minimum = -INF;
    $maximum = INF;
    $top = min($padding, $launch->y, $arrival->y);
    $bottom = max($height - $padding, $launch->y, $arrival->y);
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
