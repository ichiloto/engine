<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\Enumerations\BedPosture;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\FieldSpritePlacement;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/** One real NPC's saved posture and bed slot; rendering never changes occupancy or world state. */
final class NpcBed
{
    private ?FieldSpriteRole $pose = null;
    private ?BedPosture $selectedPosture = null;
    private ?FieldSpriteRole $transition = null;
    private bool $transitionActive = false;
    private ?string $presentationFailure = null;
    public readonly string $stateKey;
    public BedPosture $posture {
        get {
            $value = $this->state->getVariable($this->stateKey, $this->definition->posture->value);
            return is_string($value) ? (BedPosture::tryFrom($value)
                ?? throw new RuntimeException('Saved bed posture is invalid.')) : throw new RuntimeException('Saved bed posture must be a string.');
        }
    }
    public bool $isOccupied { get => $this->posture !== BedPosture::STANDING || $this->transitionActive; }
    public Vector2 $cell { get => new Vector2($this->objectDefinition->x + $this->attachment->cellX,
        $this->objectDefinition->y + $this->attachment->cellY); }
    public Vector2 $exit { get => $this->npc->placementState?->position ?? clone $this->standingExit; }

    public function __construct(public readonly NpcBedDefinition $definition, private readonly Npc $npc,
        public readonly WorldObjectDefinition $objectDefinition, public readonly WorldObjectAttachment $attachment,
        private readonly Vector2 $standingExit, private readonly GameState $state, public readonly string $mapId,
        private readonly string $assetRoot, private readonly GameScene $scene)
    {
        if ($npc->id === null) { throw new RuntimeException('An NPC with a bed requires a stable id.'); }
        $this->stateKey = 'npc-bed:' . rawurlencode($mapId) . ':' . rawurlencode($npc->id);
        $this->restorePosition();
    }

    private function restorePosition(): void
    {
        $this->npc->restoreFieldTransform($this->posture === BedPosture::STANDING ? clone $this->exit : $this->cell,
            $this->npc->heading, $this->npc->sprite);
    }

    public function requireObject(GameScene $scene): WorldObject
    {
        $object = $scene->mapManager?->findWorldObject($this->definition->objectId);
        if ($scene->currentMapId !== $this->mapId || $scene->npcManager?->findById($this->npc->id ?? '') !== $this->npc
            || $object === null || $object->definition !== $this->objectDefinition || !$object->isVisible()) {
            throw new RuntimeException('The NPC bed binding is no longer current and available.');
        }
        return $object;
    }

    public function validateDestination(GameScene $scene, BedPosture $target): void
    {
        $this->requireObject($scene);
        if ($target === BedPosture::STANDING && $this->posture !== BedPosture::STANDING) {
            $x = (int)$this->exit->x; $y = (int)$this->exit->y;
            if (!($scene->mapManager?->canMoveTo($x, $y) ?? false)
                || ($scene->player !== null && (int)$scene->player->position->x === $x && (int)$scene->player->position->y === $y)) {
                throw new RuntimeException('The bed exit is blocked; posture and saved state are unchanged.');
            }
        }
        if ($target !== BedPosture::STANDING) {
            $other = $scene->npcManager?->npcAt((int)$this->cell->x, (int)$this->cell->y);
            if (($other !== null && $other !== $this->npc)
                || ($scene->player?->position->x === $this->cell->x && $scene->player?->position->y === $this->cell->y)) {
                throw new RuntimeException('The bed slot is occupied; posture and saved state are unchanged.');
            }
        }
    }

    public function beginTransition(BedPosture $target): void
    {
        if ($this->transitionActive) { throw new RuntimeException('Only one posture transition may own an NPC.'); }
        $this->transitionActive = true;
        $definition = $this->definition->transitions[$target->value] ?? null;
        $this->transition = $definition === null ? null : new FieldSpriteRole($definition, $this->assetRoot, 'Bed transition ' . $this->stateKey);
    }

    public function advanceTransition(float $seconds): void { $this->transition?->advance($seconds); }

    public function cancelTransition(): void
    {
        $this->transition?->release();
        $this->transition = null;
        $this->transitionActive = false;
    }

    /** The command validates its current exit immediately before this one persistent write. */
    public function commitPosture(BedPosture $posture): void
    {
        $this->validateDestination($this->scene, $posture);
        $this->state->setVariable($this->stateKey, $posture->value);
        $this->cancelTransition();
        $this->restorePosition();
    }

    public function getFrame(): ?GraphicalSpriteDefinition
    {
        if ($this->transitionActive && $this->transition !== null) { return $this->transition->getFrame(new PresentationSpritePivot(), $this->npc->heading); }
        if ($this->posture === BedPosture::STANDING) { return $this->transitionActive ? $this->npc->getOrdinaryGraphicalSpriteDefinition() : null; }
        if ($this->selectedPosture !== $this->posture) {
            $this->pose?->release();
            $this->selectedPosture = $this->posture;
            $definition = $this->definition->poses[$this->posture->value] ?? null;
            $this->pose = $definition === null ? null : new FieldSpriteRole($definition, $this->assetRoot, 'Bed occupant ' . $this->stateKey);
        }
        return $this->pose?->getFrame(new PresentationSpritePivot(), $this->npc->heading);
    }

    /** Current artwork places the visual only; the logical bed cell is unchanged. */
    public function getPlacement(): ?array
    {
        $frame = $this->getFrame();
        if ($frame === null) { return null; }
        try {
            $point = $this->requireObject($this->scene)->locateAttachment($this->attachment);
            $this->presentationFailure = null;
            return $point === null ? null : FieldSpritePlacement::centre($point['x'], $point['y'], $frame);
        } catch (RuntimeException $error) { $this->reportPresentationFailure($error); return null; }
    }

    public function getPresentationWorldCanvas(): ?\Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas
    {
        try { return $this->requireObject($this->scene)->getPresentationWorldCanvas(); }
        catch (RuntimeException $error) { $this->reportPresentationFailure($error); return null; }
    }

    private function reportPresentationFailure(RuntimeException $error): void
    {
        if ($this->presentationFailure === $error->getMessage()) { return; }
        $this->presentationFailure = $error->getMessage();
        Debug::warn($this->stateKey . ' presentation unavailable: ' . $this->presentationFailure);
    }

    public function advance(float $seconds): void { if (!$this->transitionActive) { $this->pose?->advance($seconds); } }
    public function release(): void { $this->cancelTransition(); $this->pose?->release(); $this->pose = null; }
}
