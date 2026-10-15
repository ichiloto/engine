<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Vector2;
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

    private function selectRole(): ?FieldSpriteRole
    {
        if (!$this->isCurrent()) { return null; }
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
        if ($this->sign !== null) { return null; }
        if ($this->definition->resource !== null && $this->definition->sprites instanceof GraphicalSpriteDefinition) {
            return $this->scene->mapManager?->getWorldObjectResourceFrame($this);
        }
        return $this->selectRole()?->getFrame($this->definition->pivot);
    }
    public function getGraphicalSpriteWorldPosition(): Vector2 { return $this->position; }
    public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion { return null; }

    public function getTerminalSymbol(): ?string
    {
        return $this->getTerminalCells()[$this->definition->y][$this->definition->x] ?? null;
    }

    /** Styled cells of the one object; coverage may be irregular and never changes physical occupancy. */
    public function getTerminalCells(): array
    {
        if (!$this->isCurrent()) { return []; }
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
        if ($this->sign === null || !$this->isCurrent()) { return null; }
        $presentation = $this->sign->getPresentation($this->scene->getTextPreparationProvider());
        if ($presentation === null) { return null; }
        return new \Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas($this->getGraphicalSpriteId(),
            $this->definition->x, $this->definition->y, $presentation[1], $presentation[0], $this->definition->resource->sign->layer);
    }

    public function releaseTextPresentation(): void { $this->sign?->release(); }

    /** Explicit absence is valid presentation state; unavailable art is not. */
    public function hidesOwnedGlyphs(): bool
    {
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
