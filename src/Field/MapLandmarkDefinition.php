<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Localization\LocalizedText;

/** Resolved local identity. Its area belongs to an existing event or object. */
final readonly class MapLandmarkDefinition
{
    private array $markerCells;
    public function __construct(
        public string $id,
        public MapLandmarkKind $kind,
        public CellArea $area,
        public ?LocalizedText $name = null,
        public bool $secret = false,
        public ?int $approachDistance = MapLandmarkCatalog::DEFAULT_APPROACH_DISTANCE,
        public array $revealConditions = [],
        public ?array $accessConditions = null,
        public bool $automatic = false,
    ) {
        $this->markerCells = array_map(static fn(CellArea $piece) => $piece->findCenterCell(), $area->findPieces());
    }

    /** Each disconnected placement gets a marker on a real occupied cell. */
    public function getMarkerCells(): array
    {
        return $this->markerCells;
    }

    /** Distance is in physical map cells and never depends on a renderer camera. */
    public function isNear(int $x, int $y): bool
    {
        if ($this->secret || $this->approachDistance === null) { return false; }
        foreach ($this->area->cells as [$cellX, $cellY]) {
            if (abs($x - $cellX) + abs($y - $cellY) <= $this->approachDistance) { return true; }
        }
        return false;
    }
}
