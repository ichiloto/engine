<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;

/**
 * Places a complete text block at its battler's drawn body, clear of opaque UI cells.
 * Places beside the body come first; other battlers' bodies are avoided where one of those places allows,
 * but never by detaching the text from its own battler.
 */
final class BattleFeedbackPlacement
{
  /** @param list<CanvasTextLayer> $ui */
  public static function place(CanvasRectangle $battler, int $width, int $height, int $canvasWidth, int $canvasHeight, array $ui): CanvasRectangle
  {
    return self::choose($battler, $width, $height, new CanvasRectangle(0, 0, $canvasWidth, $canvasHeight), self::obstacles($ui))[0];
  }

  /** @return array{bounds: CanvasRectangle, rise: float, envelope: CanvasRectangle, overlap: float} */
  public static function moving(CanvasRectangle $battler, int $width, int $height, CanvasRectangle $area,
    array $ui, array $occupied, int $preferredRise, array $otherBattlers = [], bool $beside = false): array
  {
    $obstacles = [...self::obstacles($ui), ...$occupied];
    foreach (array_unique([max(0, $preferredRise), (int)floor(max(0, $preferredRise) / 2), 0]) as $rise) {
      if ($height + $rise > $area->height) { continue; }
      [$envelope, $overlap] = self::choose($battler, $width, $height + $rise, $area, $obstacles, true, $beside, $otherBattlers);
      if ($overlap === 0.0 || $rise === 0) {
        return ['bounds' => new CanvasRectangle($envelope->x, $envelope->y + $rise, $width, $height),
          'rise' => (float)$rise, 'envelope' => $envelope, 'overlap' => $overlap];
      }
    }
    throw new \RuntimeException('Battle feedback cannot fit the configured safe area without losing text.');
  }

  /** @return list<CanvasRectangle> */
  private static function obstacles(array $ui): array
  {
    $obstacles = [];
    foreach ($ui as $layer) {
      foreach ($layer->runs as $run) {
        if ($run->background === null || $run->text === '') { continue; }
        $obstacles[] = new CanvasRectangle($layer->x + $run->column * $layer->grid->cellWidth,
          $layer->y + $run->row * $layer->grid->cellHeight,
          mb_strlen($run->text, 'UTF-8') * $layer->grid->cellWidth, $layer->grid->cellHeight);
      }
    }
    return $obstacles;
  }

  /**
   * @param list<CanvasRectangle> $obstacles Never covered while any place clears them.
   * @param list<CanvasRectangle> $neighbours Other battlers' bodies: avoided by the places beside this one only.
   * @return array{CanvasRectangle, float} The place and its overlap with $obstacles.
   */
  private static function choose(CanvasRectangle $battler, int $width, int $height, CanvasRectangle $area, array $obstacles,
    bool $searchEdges = false, bool $beside = false, array $neighbours = []): array
  {
    if ($width > $area->width || $height > $area->height) {
      throw new \RuntimeException('Battle feedback cannot fit the configured safe area without losing text.');
    }
    $centerX = $battler->x + ($battler->width - $width) / 2;
    $centerY = $battler->y + ($battler->height - $height) / 2;
    // Animated feedback belongs at the recipient's head, not below its feet by another actor.
    $positions = $searchEdges
      ? [[$centerX, $battler->y - $height - 4], [$battler->x - $width - 4, $battler->y],
        [$battler->x + $battler->width + 4, $battler->y], [$centerX, $battler->y]]
      : [[$centerX, $battler->y - $height - 4], [$centerX, $battler->y + $battler->height + 4],
        [$battler->x - $width - 4, $centerY], [$battler->x + $battler->width + 4, $centerY], [$centerX, $centerY]];
    if ($beside) { $positions = [$positions[1], $positions[2], $positions[0], $positions[3]]; }
    $getPlace = static fn(array $position): CanvasRectangle => new CanvasRectangle(
      max($area->x, min($area->x + $area->width - $width, $position[0])),
      max($area->y, min($area->y + $area->height - $height, $position[1])), $width, $height);
    $getOverlap = static function (CanvasRectangle $candidate, array $others): float {
      $overlap = 0.0;
      foreach ($others as $other) {
        $overlap += max(0, min($candidate->x + $candidate->width, $other->x + $other->width) - max($candidate->x, $other->x))
          * max(0, min($candidate->y + $candidate->height, $other->y + $other->height) - max($candidate->y, $other->y));
      }
      return $overlap;
    };
    $attached = array_map($getPlace, $positions);
    // Beside its battler, clear of everything; then beside it over a neighbour rather than away from it.
    foreach ([[...$obstacles, ...$neighbours], $obstacles] as $avoided) {
      $free = array_find($attached, static fn(CanvasRectangle $candidate): bool => $getOverlap($candidate, $avoided) === 0.0);
      if ($free !== null) { return [$free, 0.0]; }
    }
    // UI edges add escape positions when several adjacent windows surround a battler.
    $escapes = [];
    foreach ($obstacles as $obstacle) {
      $escapes[] = [$centerX, $obstacle->y - $height];
      $escapes[] = [$centerX, $obstacle->y + $obstacle->height];
    }
    if ($searchEdges) {
      // Moving blocks must also find free side slots and corners between simultaneous envelopes.
      $xs = [$area->x, $area->x + $area->width - $width, ...array_column($positions, 0)];
      $ys = [$area->y, $area->y + $area->height - $height, ...array_column($positions, 1), ...array_column($escapes, 1)];
      foreach ($obstacles as $obstacle) {
        $xs[] = $obstacle->x - $width;
        $xs[] = $obstacle->x + $obstacle->width;
      }
      foreach (array_unique($xs) as $x) {
        foreach (array_unique($ys) as $y) { $escapes[] = [$x, $y]; }
      }
    }
    // The nearest free place to the battler's head, never merely the first one found across the area.
    $headX = $battler->x + $battler->width / 2;
    $getDistance = static fn(CanvasRectangle $place): float => abs($place->x + $place->width / 2 - $headX)
      + abs($place->y + $place->height - $battler->y);
    $escapes = array_map($getPlace, $escapes);
    usort($escapes, static fn(CanvasRectangle $a, CanvasRectangle $b): int => $getDistance($a) <=> $getDistance($b));
    $free = array_find($escapes, static fn(CanvasRectangle $candidate): bool => $getOverlap($candidate, $obstacles) === 0.0);
    if ($free !== null) { return [$free, 0.0]; }
    $best = null;
    $leastOverlap = INF;
    foreach ([...$attached, ...$escapes] as $candidate) {
      $overlap = $getOverlap($candidate, $obstacles);
      if ($overlap < $leastOverlap) { $best = $candidate; $leastOverlap = $overlap; }
    }
    // A deliberately full-screen modal still owns the screen; feedback must not paint over it.
    return [$best, (float)$leastOverlap];
  }
}
