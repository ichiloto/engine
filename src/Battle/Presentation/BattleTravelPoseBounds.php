<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;

/** Visible pose entries on a movement phase's command clock; ground occupancy belongs to the route planner. */
final readonly class BattleTravelPoseBounds
{
  private const float EPSILON = .0000001;

  /** @param non-empty-list<CanvasRectangle> $frames Visible rectangles at the original ground anchor, in playback order. */
  public function __construct(private array $frames, private int $fps, private bool $loop = false,
    private bool $fitToPhase = false)
  {
    if (!array_is_list($frames) || $frames === [] || count($frames) > 10000 || $fps < 1 || $fps > 120
      || ($fitToPhase && $loop) || !array_all($frames, static fn($box): bool => $box instanceof CanvasRectangle)) {
      throw new InvalidArgumentException('Travel pose bounds require bounded ordered rectangles and their authored playback clock.');
    }
  }

  /** Stationary entry/exit drawings keep their own extent at the departure or arrival anchor. */
  public function isAtAnchorWithinArena(Vector2 $original, Vector2 $at, CanvasRectangle $arena): bool
  {
    foreach ($this->frames as $box) {
      if (!BattleRectangleRoute::contains($this->getAllowedAnchors($box, $original, $arena), [$at->x, $at->y])) { return false; }
    }
    return true;
  }

  /**
   * Checks all displayed 120Hz ground samples without a loop proportional to trip duration.
   * Each path segment is linear; an extent can cross each arena edge at most once there.
   * The authored entry is then checked only over the resulting unsafe tick interval.
   * Repeated drawings retain their distinct playback entries and fitted one-shots can skip entries on short trips.
   */
  public function isWithinArena(BattleTravelPlan $plan, CanvasRectangle $arena, bool $returning = false): bool
  {
    if (!$plan->isPossible) { return false; }
    $seconds = $returning ? $plan->returnSeconds : $plan->advanceSeconds;
    $frameCount = ceil($seconds * BattleCommandTimeline::FPS);
    if (!is_finite($frameCount) || $frameCount >= PHP_INT_MAX) {
      throw new InvalidArgumentException('Travel duration exceeds the command clock range.');
    }
    $ticks = max(1, (int)$frameCount);
    $points = $plan->getWaypoints();
    $origin = $points[0];
    if ($plan->distance === 0.0) {
      return BattleRectangleRoute::contains($this->getAllowedAnchors($this->frames[$this->getEntryAtTick(0, $ticks) % count($this->frames)],
        $origin, $arena), [$origin->x, $origin->y]);
    }
    $distance = 0.0;
    foreach (array_slice($points, 1) as $index => $end) {
      $start = $points[$index];
      $length = hypot($end->x - $start->x, $end->y - $start->y);
      if ($length === 0.0) { continue; }
      $begin = $distance / $plan->distance;
      $finish = ($distance + $length) / $plan->distance;
      $distance += $length;
      $low = max(0, (int)ceil(($returning ? 1 - $finish : $begin) * $ticks - 1e-7));
      $high = min($ticks - 1, (int)floor(($returning ? 1 - $begin : $finish) * $ticks + 1e-7));
      if ($low > $high) { continue; }
      foreach ($this->frames as $entry => $box) {
        $allowed = $this->getAllowedAnchors($box, $origin, $arena);
        if ($allowed[0] > $allowed[2] || $allowed[1] > $allowed[3]) {
          if ($this->hasEntryDuring($entry, $low, $high, $ticks)) { return false; }
          continue;
        }
        foreach ([0, 1] as $axis) {
          $from = $axis === 0 ? $start->x : $start->y;
          $delta = ($axis === 0 ? $end->x : $end->y) - $from;
          $value = static fn(int $tick): float => $from + $delta
            * (($returning ? 1 - $tick / $ticks : $tick / $ticks) - $begin) / ($finish - $begin);
          foreach ([false, true] as $upper) {
            $outside = $upper
              ? static fn(int $tick): bool => $value($tick) > $allowed[$axis + 2] + self::EPSILON
              : static fn(int $tick): bool => $value($tick) < $allowed[$axis] - self::EPSILON;
            $left = $outside($low); $right = $outside($high);
            if (!$left && !$right) { continue; }
            $first = $low; $last = $high;
            if ($left !== $right) {
              // Find the first tick whose truth value differs, keeping exact endpoint comparisons.
              $a = $low; $b = $high;
              while ($a + 1 < $b) {
                $middle = $a + intdiv($b - $a, 2);
                if ($outside($middle) === $left) { $a = $middle; } else { $b = $middle; }
              }
              if ($left) { $last = $a; } else { $first = $b; }
            }
            if ($this->hasEntryDuring($entry, $first, $last, $ticks)) { return false; }
          }
        }
      }
    }
    return true;
  }

  /** @return array{float, float, float, float} */
  private function getAllowedAnchors(CanvasRectangle $box, Vector2 $original, CanvasRectangle $arena): array
  {
    return [$arena->x - ($box->x - $original->x), $arena->y - ($box->y - $original->y),
      $arena->x + $arena->width - ($box->x + $box->width - $original->x),
      $arena->y + $arena->height - ($box->y + $box->height - $original->y)];
  }

  private function getEntryAtTick(int $tick, int $ticks): int
  {
    $index = EffectPlaybackTiming::getFrameCountForElapsed($tick / BattleCommandTimeline::FPS,
      $this->fitToPhase ? ($ticks / BattleCommandTimeline::FPS) / count($this->frames) : 1 / $this->fps);
    return $this->loop ? $index : min(count($this->frames) - 1, $index);
  }

  private function hasEntryDuring(int $entry, int $first, int $last, int $ticks): bool
  {
    $from = $this->getEntryAtTick($first, $ticks);
    $to = $this->getEntryAtTick($last, $ticks);
    if ($this->loop) {
      // fps <= command FPS: raw entry indices advance by at most one each tick.
      $offset = ($entry - $from % count($this->frames) + count($this->frames)) % count($this->frames);
      return $to - $from >= $offset;
    }
    if ($entry < $from || $entry > $to) { return false; }
    // A trip fitted to fewer ticks than drawings can skip entries. Locate the actual first selected one.
    $a = $first; $b = $last;
    while ($a < $b) {
      $middle = $a + intdiv($b - $a, 2);
      if ($this->getEntryAtTick($middle, $ticks) < $entry) { $a = $middle + 1; } else { $b = $middle; }
    }
    return $this->getEntryAtTick($a, $ticks) === $entry;
  }
}
