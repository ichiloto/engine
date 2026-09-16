<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\MovementRouteException;
use Ichiloto\Engine\Scenes\Game\GameScene;
use SplQueue;

/** Bounded cardinal search between authored waypoints; execution still checks every step. */
final class MovementRoutePlanner
{
  public const int MAX_VISITED_CELLS = 65536;
  /** @return list<array{direction: string}> */
  public static function plan(GameScene $scene, Vector2 $origin, Vector2 $target, bool $movingNpc): array
  {
    $width = $scene->camera->worldSpaceWidth;
    $height = $scene->camera->worldSpaceHeight;
    foreach ([$origin, $target] as $position) {
      if ($width < 1 || $height < 1 || !is_finite($position->x) || !is_finite($position->y)
        || floor($position->x) !== $position->x || floor($position->y) !== $position->y
        || $position->x < 0 || $position->x >= $width || $position->y < 0 || $position->y >= $height) {
        throw new MovementRouteException('Movement-route origin and waypoint must be integral cells inside the current map.');
      }
    }
    $start = intval($origin->y) * $width + intval($origin->x);
    $end = intval($target->y) * $width + intval($target->x);
    $queue = new SplQueue();
    $queue->enqueue($start);
    $previous = [$start => -1];
    while (!$queue->isEmpty() && !array_key_exists($end, $previous)) {
      $current = $queue->dequeue();
      $x = $current % $width;
      $y = intdiv($current, $width);
      foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dy]) {
        $nx = $x + $dx;
        $ny = $y + $dy;
        $key = $ny * $width + $nx;
        if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height || array_key_exists($key, $previous)
          || !$scene->mapManager->canMoveTo($nx, $ny)
          || ($movingNpc && $scene->player !== null
            && intval($scene->player->position->x) === $nx && intval($scene->player->position->y) === $ny)) {
          continue;
        }
        if (count($previous) >= self::MAX_VISITED_CELLS) {
          throw new MovementRouteException('Movement-route search exceeded its cell budget; add nearer authored waypoints.');
        }
        $previous[$key] = $current;
        if ($key === $end) {
          break;
        }
        $queue->enqueue($key);
      }
    }
    if (!array_key_exists($end, $previous)) {
      throw new MovementRouteException(sprintf('No walkable route on map "%s" from (%d, %d) to waypoint (%d, %d).',
        $scene->currentMapId, $origin->x, $origin->y, $target->x, $target->y));
    }
    $steps = [];
    for ($key = $end; $key !== $start; $key = $previous[$key]) {
      $parent = $previous[$key];
      $steps[] = ['direction' => match (true) {
        intdiv($key, $width) < intdiv($parent, $width) => 'up',
        intdiv($key, $width) > intdiv($parent, $width) => 'down',
        $key % $width < $parent % $width => 'left',
        default => 'right',
      }];
    }
    return array_reverse($steps);
  }
}
