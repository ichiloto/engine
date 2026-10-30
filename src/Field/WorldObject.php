<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasWorldSubject;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;

/** One installed map's non-blocking graphical subject, selected from live world state. */
final class WorldObject implements GraphicalSpriteProviderInterface
{
    private ?FieldSpriteRole $role = null;
    private bool $released = false;
    private ?string $selectedVariant = null;
    private bool $selected = false;
    private ?WorldSignPlayback $sign = null;

    public function __construct(public readonly WorldObjectDefinition $definition, private readonly GameScene $scene,
        public readonly string $mapId, private readonly string $assetRoot)
    {
        if ($definition->resource?->sign !== null) { $this->sign = new WorldSignPlayback($definition, $assetRoot); }
    }

    public Vector2 $position { get => new Vector2($this->definition->x, $this->definition->y); }

    public function isCurrent(): bool
    {
        return !$this->released && $this->scene->currentMapId === $this->mapId
            && $this->scene->mapManager?->findWorldObject($this->definition->id) === $this;
    }

    public function isVisible(): bool
    {
        return $this->isCurrent() && $this->definition->isAvailable($this->scene->gameState, $this->scene->party);
    }

    private function selectRole(): ?FieldSpriteRole
    {
        if (!$this->isVisible()) { return null; }
        $variant = $this->definition->selectVariant($this->scene->gameState, $this->scene->party);
        if (!$this->selected || $this->selectedVariant !== $variant['id']) {
            $this->role?->release();
            $this->selected = true;
            $this->selectedVariant = $variant['id'];
            $this->role = $variant['sprites'] === null ? null
                : new FieldSpriteRole($variant['sprites'], $this->assetRoot, 'World object ' . $this->mapId . '/' . $this->definition->id);
        }
        return $this->role;
    }

    public function getGraphicalSpriteId(): string { return 'world-object:' . $this->mapId . ':' . $this->definition->id; }
    public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition
    {
        if (!$this->isVisible()) { return null; }
        if ($this->sign !== null) { return null; }
        if ($this->definition->resource !== null && $this->definition->sprites instanceof GraphicalSpriteDefinition) {
            return $this->scene->mapManager?->getWorldObjectResourceFrame($this);
        }
        return $this->selectRole()?->getFrame($this->definition->pivot);
    }
    public function getGraphicalSpriteWorldPosition(): Vector2 { return $this->position; }
    public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion { return null; }

    /**
     * Where an attachment lies on the art drawn now, in field pixels from the
     * map origin: the drawn image's pivot sits at the anchor cell's ground
     * point, so the attachment is its point's distance from the pivot away.
     * Null while no art is drawn; the terminal cell then presents it.
     *
     * @return array{x: float, y: float}|null
     */
    public function locateAttachment(WorldObjectAttachment $attachment): ?array
    {
        $frame = $this->getGraphicalSpriteDefinition();
        $canvas = $frame === null ? $this->getPresentationWorldCanvas() : null;
        if ($frame === null && $canvas === null) { return null; }
        $width = $frame?->width ?? $canvas->canvas->width;
        $height = $frame?->height ?? $canvas->canvas->height;
        $pivot = $frame === null ? $canvas->pivot : $frame->pivot;
        return ['x' => ($this->definition->x + .5) * FieldViewport::TILE_SIZE + ($attachment->pointX - ($pivot?->x ?? .5)) * $width,
            'y' => ($this->definition->y + 1) * FieldViewport::TILE_SIZE - ($frame?->lift ?? 0)
                + ($attachment->pointY - ($pivot?->y ?? 1.0)) * $height];
    }

    public function getTerminalSymbol(): ?string
    {
        return $this->getTerminalCells()[$this->definition->y][$this->definition->x] ?? null;
    }

    /** Styled cells of the one object; coverage may be irregular and never changes physical occupancy. */
    public function getTerminalCells(): array
    {
        if (!$this->isVisible()) { return []; }
        $presentation = $this->definition->selectVariant($this->scene->gameState, $this->scene->party)['terminal'];
        if ($presentation === null) { return []; }
        if (!$presentation->usesOwnedFootprint()) {
            return [$this->definition->y => [$this->definition->x => $presentation->getSymbol(' ')]];
        }
        $cells = [];
        foreach ($this->definition->terminalFootprint as $cell) {
            if ($this->scene->mapManager?->layers?->getGameplayLayerAt($cell['x'], $cell['y'])->name !== $cell['layer']) { continue; }
            $cells[$cell['y']][$cell['x']] = $presentation->getSymbol($cell['symbol']);
        }
        return $cells;
    }

    public function getPresentationWorldCanvas(): ?\Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas
    {
        if ($this->sign === null || !$this->isVisible()) { return null; }
        $presentation = $this->sign->getPresentation($this->scene->getTextPreparationProvider());
        if ($presentation === null) { return null; }
        return new \Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas($this->getGraphicalSpriteId(),
            $this->definition->x, $this->definition->y, $presentation[1], $presentation[0], $this->definition->resource->sign->layer);
    }

    /** A static miniature uses the same current resource and ownership as the field. */
    public function getOverviewSubject(): ?CanvasWorldSubject
    {
        if (!$this->isVisible()) { return null; }
        if ($this->sign !== null) {
            $group = $this->getPresentationWorldCanvas();
            if ($group === null) { return null; }
            // Printed sign text is represented by the atlas's readable landmark
            // names. Retain the actual board imagery, size and ground attachment.
            return new CanvasWorldSubject($group->id, $this->definition->x, $this->definition->y,
                $group->canvas->width / FieldViewport::TILE_SIZE, $group->canvas->height / FieldViewport::TILE_SIZE,
                $group->canvas->width, $group->canvas->height, $group->pivot, $group->canvas->images, $group->layer);
        }
        $frame = $this->selectRole()?->getStillFrame($this->definition->pivot);
        if ($frame === null) { return null; }
        return new CanvasWorldSubject($this->getGraphicalSpriteId(), $this->definition->x, $this->definition->y,
            $frame->width / FieldViewport::TILE_SIZE, $frame->height / FieldViewport::TILE_SIZE,
            $frame->width, $frame->height, $this->definition->pivot,
            [new CanvasImage('still', $frame->asset, new CanvasRectangle(0, 0, $frame->width, $frame->height),
                sourceRect: $frame->sourceRect)], $frame->layer, $frame->lift / FieldViewport::TILE_SIZE, $frame->quarterTurns);
    }

    public function releaseTextPresentation(): void { $this->sign?->release(); }

    /** Explicit absence is valid presentation state; unavailable art is not. */
    public function hidesOwnedGlyphs(): bool
    {
        if (!$this->isVisible()) { return false; }
        // Tile-layer coverage hides its own glyphs only when those tiles can actually draw.
        if ($this->definition->tilePresentation !== [] && $this->definition->sprites === null) { return false; }
        if ($this->sign !== null) { return $this->getPresentationWorldCanvas() !== null; }
        if ($this->definition->resource !== null && $this->definition->sprites instanceof GraphicalSpriteDefinition) {
            return $this->getGraphicalSpriteDefinition() !== null;
        }
        $role = $this->selectRole();
        return $this->isCurrent() && ($role === null || $role->getFrame($this->definition->pivot) !== null);
    }

    public function advanceGraphicalAnimation(float $seconds): void
    {
        if ($this->definition->resource === null || !$this->definition->sprites instanceof GraphicalSpriteDefinition) {
            $this->selectRole()?->advance($seconds);
        }
    }
    public function release(): void
    {
        $this->released = true;
        $this->role?->release();
        $this->role = null;
        $this->sign?->release();
    }
}
