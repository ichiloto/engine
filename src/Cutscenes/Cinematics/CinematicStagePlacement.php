<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/** Chooses physical ground for independent subject-relative cast, before any stage ownership changes. */
final readonly class CinematicStagePlacement
{
  public const int SEARCH_RADIUS = 3;

  public function __construct(private GameScene $scene) {}

  public function resolve(string $id, Vector2 $preferred, Vector2 $anchor): Vector2
  {
    if ($this->scene->mapManager === null) {
      throw new RuntimeException('Subject-relative staging requires the current map physical occupancy.');
    }
    if ($this->canStandAt($id, (int)$preferred->x, (int)$preferred->y)
      && $this->isVisibleAt((int)$preferred->x, (int)$preferred->y)) { return $preferred; }
    $queue = [[(int)$anchor->x, (int)$anchor->y, 0]];
    $seen = [(int)$anchor->x . ':' . (int)$anchor->y => true];
    for ($index = 0; $index < count($queue); $index++) {
      [$x, $y, $distance] = $queue[$index];
      if ($distance === self::SEARCH_RADIUS) { continue; }
      foreach ([MovementHeading::NORTH, MovementHeading::EAST, MovementHeading::SOUTH, MovementHeading::WEST] as $heading) {
        $direction = $heading->getDirection(); $nx = $x + (int)$direction->x; $ny = $y + (int)$direction->y;
        $key = $nx . ':' . $ny;
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        if (!$this->canStandAt($id, $nx, $ny)) { continue; }
        if ($this->isVisibleAt($nx, $ny)) {
          Debug::warn(sprintf('Staged actor "%s" moved from unsafe relative cell (%d,%d) to visible floor (%d,%d).',
            $id, $preferred->x, $preferred->y, $nx, $ny));
          return new Vector2($nx, $ny);
        }
        $queue[] = [$nx, $ny, $distance + 1];
      }
    }
    throw new RuntimeException(sprintf('Staged actor "%s" has no visible unoccupied floor within %d cells of anchor (%d,%d); requested (%d,%d).',
      $id, self::SEARCH_RADIUS, $anchor->x, $anchor->y, $preferred->x, $preferred->y));
  }

  private function isVisibleAt(int $x, int $y): bool
  {
    $camera = $this->scene->camera; $origin = $camera->getWorldOrigin();
    return $x >= $origin['x'] && $y >= $origin['y']
      && $x < $origin['x'] + $camera->screen->getWidth() && $y < $origin['y'] + $camera->screen->getHeight();
  }

  private function canStandAt(string $id, int $x, int $y): bool
  {
    $player = $this->scene->player;
    if ($player !== null && (int)$player->position->x === $x && (int)$player->position->y === $y) { return false; }
    if ($this->scene->npcManager?->npcAt($x, $y) !== null) { return false; }
    foreach ($this->scene->cinematicStage?->all() ?? [] as $actor) {
      if ($actor->id !== $id && $actor->isVisible && (int)$actor->position->x === $x && (int)$actor->position->y === $y) { return false; }
    }
    $collision = null;
    if ($this->scene->mapManager->canMoveTo($x, $y, $collision)) { return true; }
    // Replacing this same staged actor may reuse its own valid floor, never another occupant's cell.
    return $collision === CollisionType::NPC && $this->scene->cinematicStage?->actorAt($x, $y)?->id === $id
      && !$this->scene->mapManager->getCollision($x, $y)->blocksMovement();
  }
}
