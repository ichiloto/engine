<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/** One real NPC's resting-art clock, selected by its existing saved placement. */
final class NpcPlacementPose
{
    private ?string $selection = null;
    private ?FieldSpriteRole $role = null;
    private bool $released = false;

    public function __construct(private readonly NpcPlacementState $placement, private readonly string $assetRoot,
        private readonly string $identity) {}

    private function getRole(): ?FieldSpriteRole
    {
        if ($this->released) { return null; }
        $selection = $this->placement->selection;
        if ($selection !== $this->selection) {
            $this->role?->release();
            $this->role = null;
            $this->selection = $selection;
            try {
                $definition = $this->placement->definition->getPose($selection);
                if ($definition !== null) { $this->role = new FieldSpriteRole($definition, $this->assetRoot,
                    'NPC placement ' . $this->identity . '/' . $selection); }
            } catch (InvalidArgumentException | RuntimeException $error) {
                Debug::warn('NPC placement ' . $this->identity . '/' . $selection . ' art unavailable: ' . $error->getMessage());
            }
        }
        return $this->role;
    }

    public function getFrame(MovementHeading $heading): ?GraphicalSpriteDefinition
    {
        return $this->getRole()?->getFrame(new PresentationSpritePivot(), $heading);
    }

    public function advance(float $seconds): void
    {
        $role = $this->getRole();
        $role?->resume();
        $role?->advance($seconds);
    }

    public function pause(): void { $this->getRole()?->pause(); }

    public function release(): void
    {
        $this->released = true;
        $this->role?->release();
        $this->role = null;
    }
}
