<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;

/** Places a complete text block around its battler, clear of opaque UI cells. */
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
    $obstacles = [...self::obstacles($ui), ...$occupied, ...$otherBattlers];
    foreach (array_unique([max(0, $preferredRise), (int)floor(max(0, $preferredRise) / 2), 0]) as $rise) {
      if ($height + $rise > $area->height) { continue; }
      [$envelope, $overlap] = self::choose($battler, $width, $height + $rise, $area, $obstacles, true, $beside);
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

  /** @return array{CanvasRectangle, float} */
  private static function choose(CanvasRectangle $battler, int $width, int $height, CanvasRectangle $area, array $obstacles,
    bool $searchEdges = false, bool $beside = false): array
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
    // UI edges add escape positions when several adjacent windows surround a battler.
    foreach ($obstacles as $obstacle) {
      $positions[] = [$centerX, $obstacle->y - $height];
      $positions[] = [$centerX, $obstacle->y + $obstacle->height];
    }
    $candidates = static function () use ($positions, $searchEdges, $area, $width, $height, $obstacles): \Generator {
      yield from $positions;
      if (!$searchEdges) { return; }
      // Moving blocks must also find free side slots and corners between simultaneous envelopes.
      $xs = [$area->x, $area->x + $area->width - $width, ...array_column($positions, 0)];
      $ys = [$area->y, $area->y + $area->height - $height, ...array_column($positions, 1)];
      foreach ($obstacles as $obstacle) {
        $xs[] = $obstacle->x - $width;
        $xs[] = $obstacle->x + $obstacle->width;
      }
      foreach (array_unique($xs) as $x) {
        foreach (array_unique($ys) as $y) { yield [$x, $y]; }
      }
    };
    $best = null;
    $leastOverlap = INF;
    foreach ($candidates() as [$x, $y]) {
      $candidate = new CanvasRectangle(max($area->x, min($area->x + $area->width - $width, $x)),
        max($area->y, min($area->y + $area->height - $height, $y)), $width, $height);
      $overlap = 0;
      foreach ($obstacles as $obstacle) {
        $overlap += max(0, min($candidate->x + $width, $obstacle->x + $obstacle->width) - max($candidate->x, $obstacle->x))
          * max(0, min($candidate->y + $height, $obstacle->y + $obstacle->height) - max($candidate->y, $obstacle->y));
      }
      if ($overlap === 0.0 || $overlap === 0) { return [$candidate, 0.0]; }
      if ($overlap < $leastOverlap) { $best = $candidate; $leastOverlap = $overlap; }
    }
    // A deliberately full-screen modal still owns the screen; feedback must not paint over it.
    return [$best, (float)$leastOverlap];
  }
}
