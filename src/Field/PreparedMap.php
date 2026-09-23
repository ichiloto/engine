<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;

/** Validated map content awaiting a single field-state commit. */
final readonly class PreparedMap
{
    /**
     * @param array<string, mixed> $data
     * @param array<int, string[]> $tiles
     * @param int[][] $collisions
     * @param MapTrigger[] $mapTriggers
     * @param EventTrigger[] $eventTriggers
     */
    public function __construct(
        public array $data,
        public array $tiles,
        public array $collisions,
        public ?GraphicalTileDefinition $tiles2d,
        public array $mapTriggers,
        public array $eventTriggers,
    ) {
    }
}
