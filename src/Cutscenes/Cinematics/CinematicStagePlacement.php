<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** Validates authored ground for independent cast before any stage ownership changes. */
final readonly class CinematicStagePlacement
{
  public function __construct(private GameScene $scene) {}

  public function assertCanPlace(string $id, Vector2 $position): void
  {
    if ($this->scene->mapManager === null) {
      throw new RuntimeException('Subject-relative staging requires the current map physical occupancy.');
    }
    if (!$this->canStandAt($id, (int)$position->x, (int)$position->y)) {
      throw new RuntimeException(sprintf('Staged actor "%s" requires unoccupied floor at authored relative cell (%d,%d).',
        $id, $position->x, $position->y));
    }
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
