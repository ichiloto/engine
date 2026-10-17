<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\MapOverview;

/** One read-only view of the installed map and knowledge; it never owns gameplay. */
final readonly class MapAtlasSnapshot
{
    public function __construct(public string $name, public string $worldId, public MapOverview $overview,
        public array $subjects, public array $landmarks, public float $playerX, public float $playerY,
        public MovementHeading $heading, public bool $global = false, public bool $canSwitch = true) {}
}
