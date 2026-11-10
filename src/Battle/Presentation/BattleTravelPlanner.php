<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;

/** Continuous rectangular-footing visibility search; no assets, gameplay state or renderer access. */
final class BattleTravelPlanner
{
  /** 64 other footings, including the target: at most 262 visibility vertices, no canvas-size raster search. */
  public const int MAX_FOOTINGS = BattleRectangleRoute::MAX_OBSTACLES;
  private const float EPSILON = 0.0000001;

  /**
   * $reach is the strike offset from the moving foot: x is forward, y is
   * canvas-down. An exact strike point wins; otherwise the target's near body
   * edge is used at target-foot + reach.y, bounded to the visible body height.
   * Footings are absolute occupied ground rectangles, supplied by the adapter
   * using BattleFormationClearance::getFooting(), excluding the source/target.
   * Touching ground boundaries is permitted; overlapping their interiors is not.
   * Equal horizontal foot anchors require an explicit facing sign (-1 left, +1 right).
   * The source's visible body and footing remain inside the arena throughout.
   * @param list<CanvasRectangle> $blockers Other battlers' ground footings, never whole tall sprite bounds.
   */
  public static function plan(BattleTravelSubject $source, BattleTravelSubject $target, CanvasRectangle $arena,
    Vector2 $reach, array $blockers = [], ?Vector2 $strikePoint = null,
    float $advanceSpeed = 600.0, float $returnSpeed = 900.0, ?int $facingSign = null, bool $usesFarPose = false,
    ?BattlePoseAngleVariant $arrivalPose = null): BattleTravelPlan
  {
    if (!is_finite($reach->x) || $reach->x < 0 || !is_finite($reach->y)
      || ($strikePoint !== null && (!is_finite($strikePoint->x) || !is_finite($strikePoint->y)))
      || !is_finite($advanceSpeed) || $advanceSpeed <= 0 || !is_finite($returnSpeed) || $returnSpeed <= 0
      || ($facingSign !== null && !in_array($facingSign, [-1, 1], true))
      || !array_is_list($blockers) || !array_all($blockers, static fn($box): bool => $box instanceof CanvasRectangle)) {
      throw new InvalidArgumentException('Battle travel requires finite forward reach, positive speeds and a list of ground rectangles.');
    }
    $sameX = abs($target->footX - $source->footX) <= self::EPSILON;
    if (($sameX && $facingSign === null)
      || (!$sameX && $facingSign !== null && $facingSign !== ($target->footX < $source->footX ? -1 : 1))) {
      throw new InvalidArgumentException('Battle travel must face its target; equal horizontal anchors require an explicit facing sign.');
    }
    $sign = $facingSign ?? ($target->footX < $source->footX ? -1 : 1);
    $contact = [$strikePoint?->x ?? ($sign < 0 ? $target->body->x + $target->body->width : $target->body->x),
      $strikePoint?->y ?? min($target->body->y + $target->body->height, max($target->body->y, $target->footY + $reach->y))];
    $blocked = static fn(BattleTravelFailure $failure): BattleTravelPlan => new BattleTravelPlan([],
      $contact[0], $contact[1], $sign, $advanceSpeed, $returnSpeed, $failure);
    if (count($blockers) + 1 > self::MAX_FOOTINGS) { return $blocked(BattleTravelFailure::CAPACITY); }
    $start = [$source->footX, $source->footY];
    $end = [$contact[0] - $sign * $reach->x, $contact[1] - $reach->y];
    $foot = [$source->footing->x - $source->footX, $source->footing->y - $source->footY,
      $source->footing->x + $source->footing->width - $source->footX,
      $source->footing->y + $source->footing->height - $source->footY];
    // Visual height constrains the arena, but only the existing footing occupies other battlers' ground.
    $allowed = [$arena->x - min($foot[0], $source->body->x - $source->footX),
      $arena->y - min($foot[1], $source->body->y - $source->footY),
      $arena->x + $arena->width - max($foot[2], $source->body->x + $source->body->width - $source->footX),
      $arena->y + $arena->height - max($foot[3], $source->body->y + $source->body->height - $source->footY)];
    // Stay on the approaching side of the target anchor, including a vertically aligned start.
    // Returning samples the same route without turning around.
    if ($sign < 0) { $allowed[0] = max($allowed[0], $target->footX); }
    else { $allowed[2] = min($allowed[2], $target->footX); }
    if (!BattleRectangleRoute::contains($allowed, $start) || !BattleRectangleRoute::contains($allowed, $end)) {
      return $blocked(BattleTravelFailure::OUTSIDE_ARENA);
    }
    $obstacles = array_map(static fn(CanvasRectangle $box): array => [
      $box->x - $foot[2], $box->y - $foot[3],
      $box->x + $box->width - $foot[0], $box->y + $box->height - $foot[1]], [...$blockers, $target->footing]);
    if (BattleRectangleRoute::isOccupied($start, $obstacles)) { return $blocked(BattleTravelFailure::START_OCCUPIED); }
    if (BattleRectangleRoute::isOccupied($end, $obstacles)) { return $blocked(BattleTravelFailure::CONTACT_OCCUPIED); }
    $route = BattleRectangleRoute::findRoute($start, $end, $allowed, $obstacles);
    return $route === null ? $blocked(BattleTravelFailure::NO_ROUTE)
      : new BattleTravelPlan($route, $contact[0], $contact[1], $sign, $advanceSpeed, $returnSpeed, usesFarPose: $usesFarPose, arrivalPose: $arrivalPose);
  }
}
