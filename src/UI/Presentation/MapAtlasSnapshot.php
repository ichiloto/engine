<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\MapOverview;
use Ichiloto\Engine\Field\Waymarks\FieldWaymark;

/** One read-only view of the installed map and knowledge; it never owns gameplay. */
final readonly class MapAtlasSnapshot
{
    /** @param list<FieldWaymark> $waymarks Current objectives on this map, or the ways toward them. */
    public function __construct(public string $name, public string $worldId, public MapOverview $overview,
        public array $subjects, public array $landmarks, public float $playerX, public float $playerY,
        public MovementHeading $heading, public bool $global = false, public bool $canSwitch = true,
        public string $terminalView = 'floorplan', public array $waymarks = []) {}

    /** The same view with other switching or waymarks. */
    public function with(?bool $canSwitch = null, ?array $waymarks = null): self
    {
        return new self($this->name, $this->worldId, $this->overview, $this->subjects, $this->landmarks, $this->playerX,
            $this->playerY, $this->heading, $this->global, $canSwitch ?? $this->canSwitch, $this->terminalView,
            $waymarks ?? $this->waymarks);
    }
}
