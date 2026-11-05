<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** Find a common ray through the presented targets in selection order. */
final class BattleStraightShot
{
  /** @param non-empty-list<CanvasRectangle> $targets */
  public static function plan(Vector2 $origin, array $targets): ?BattlePresentationPath
  {
    $last = $targets[array_key_last($targets)];
    $dx = $last->x + $last->width / 2 - $origin->x;
    $dy = $last->y + $last->height / 2 - $origin->y;
    $swap = abs($dy) > abs($dx);
    $sign = ($swap ? $dy : $dx) < 0 ? -1 : 1;
    $axes = array_map(static function (CanvasRectangle $rect) use ($origin, $swap, $sign): array {
      $forward = $swap ? $rect->y - $origin->y : $rect->x - $origin->x;
      $depth = $swap ? $rect->height : $rect->width;
      $across = $swap ? $rect->x - $origin->x : $rect->y - $origin->y;
      $breadth = $swap ? $rect->width : $rect->height;
      return $sign > 0 ? [$forward, $forward + $depth, $across, $across + $breadth]
        : [-$forward - $depth, -$forward, $across, $across + $breadth];
    }, $targets);
    $forward = abs($swap ? $dy : $dx);
    $preferred = $forward > 0 ? ($swap ? $dx : $dy) / $forward : 0.0;
    $candidates = [];
    foreach ([1, -1] as $side) {
      $interval = self::getSlopeInterval($axes, $side);
      if ($interval === null) { continue; }
      [$min, $max] = $interval;
      $preferredSide = $preferred * $side;
      $slope = $preferredSide >= $min && $preferredSide <= $max ? $preferredSide
        : tan((atan($min) + atan($max)) / 2);
      $candidates[] = [$slope * $side, abs(atan($slope * $side) - atan($preferred))];
    }
    if ($candidates === []) { return null; }
    usort($candidates, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
    $slope = $candidates[0][0];
    $contact = 0.0;
    foreach ($axes as [$near, , $bottom, $top]) {
      $crossing = $slope > 0 ? $bottom / $slope : ($slope < 0 ? $top / $slope : 0.0);
      $contact = max($contact, $near, $crossing);
    }
    if ($contact === 0.0) {
      // The release is already inside the final body. Keep a direction for a
      // single ray instead of inventing an exit that falls outside that body.
      $contact = min($forward, $axes[array_key_last($axes)][1]);
    }
    $along = $contact * $sign;
    $across = $contact * $slope;
    return new BattlePresentationPath([[$origin->x, $origin->y],
      [$origin->x + ($swap ? $across : $along), $origin->y + ($swap ? $along : $across)]]);
  }

  /**
   * For nonnegative slope, a box occupies [nearX, farX] ∩ [nearY/s, farY/s]
   * along the forward axis. The greatest earlier entry must fit inside
   * every later exit. Comparing the two intervals yields bounds on s.
   *
   * @param list<array{float, float, float, float}> $axes
   * @return ?array{float, float}
   */
  private static function getSlopeInterval(array $axes, int $side): ?array
  {
    $min = $entryX = 0.0;
    $max = INF;
    $entryY = -INF;
    foreach ($axes as [$nearX, $farX, $nearY, $farY]) {
      if ($side < 0) { [$nearY, $farY] = [-$farY, -$nearY]; }
      if ($farX <= 0.0) { return null; }
      $entryX = max($entryX, $nearX);
      $entryY = max($entryY, $nearY);
      if ($entryX > $farX) { return null; }
      if ($entryY > $farY) { return null; }
      if ($entryX > 0) { $max = min($max, $farY / $entryX); }
      elseif ($farY < 0) { return null; }
      $min = max($min, $entryY / $farX);
      if ($min > $max) { return null; }
    }
    return [$min, $max];
  }
}
