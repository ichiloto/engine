<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** Persistent placement selection for one real NPC, independent of its temporary route. */
final class NpcPlacementState
{
    public function __construct(public readonly NpcPlacementSet $definition, private readonly Npc $npc,
        private readonly GameState $state, public readonly string $mapId, private readonly string $npcId) {}

    public string $selection { get => $this->definition->readSelection($this->state, $this->mapId, $this->npcId); }
    public Vector2 $position {
        get {
            $target = $this->definition->placements[$this->selection];
            return new Vector2($target['x'], $target['y']);
        }
    }

    /** Commit the already completed physical route, never teleport to an unvisited destination. */
    public function commitPlacement(GameScene $scene, string $id): void
    {
        if ($scene->currentMapId !== $this->mapId || $scene->npcManager?->findPresentById($this->npcId) !== $this->npc) {
            throw new RuntimeException('NPC placement requires the same current real map subject.');
        }
        $target = $this->definition->placements[$id] ?? throw new RuntimeException('Select a declared NPC placement.');
        if ($this->npc->bed?->isOccupied) { throw new RuntimeException('Get the NPC out of bed before committing a standing placement.'); }
        $x = $target['x']; $y = $target['y'];
        if ((int)$this->npc->position->x !== $x || (int)$this->npc->position->y !== $y) {
            throw new RuntimeException('The real NPC must reach its authored placement before it can be committed.');
        }
        if ($scene->mapManager === null || $scene->mapManager->getCollision($x, $y)->blocksMovement()
            || (($player = $scene->player) !== null && (int)$player->position->x === $x && (int)$player->position->y === $y)
            || array_any($scene->npcManager->npcs, fn(Npc $other): bool => $other !== $this->npc
                && $scene->npcManager->canStageNpc($other) && (int)$other->position->x === $x && (int)$other->position->y === $y)) {
            throw new RuntimeException('The NPC placement is occupied or blocks movement; saved selection is unchanged.');
        }
        $staged = $scene->cinematicStage?->actorAt($x, $y);
        if ($staged !== null && $staged->subject?->subject !== $this->npc) {
            throw new RuntimeException('A staged subject occupies the NPC placement.');
        }
        $this->state->setVariable(NpcPlacementSet::getStateKey($this->mapId, $this->npcId), $id);
        $this->npc->restoreFieldTransform(new Vector2($x, $y), $target['facing'], $this->npc->sprite);
        $scene->cinematicStage?->commitSubjectTransforms($this->npc);
        $scene->requestFieldPresentationReconciliation();
    }
}
