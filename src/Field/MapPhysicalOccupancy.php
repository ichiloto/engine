<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use InvalidArgumentException;

/** Final physical cells, independent of terminal layers and graphical resources. */
final readonly class MapPhysicalOccupancy
{
    public const string DATA_KEY = 'occupancy';

    /** @var list<list<int>> The existing runtime collision-grid representation. */
    public array $collisionGrid;

    /**
     * Declared rows contain CollisionType cases, not glyphs or coerced integers.
     * Ragged maps retain their exact row lengths; missing cells are not padded.
     */
    public function __construct(mixed $rows, MapLayerSet $layers, string $context = 'Map occupancy')
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new InvalidArgumentException("{$context} must be a zero-based list of rows.");
        }
        $tiles = $layers->getComposedGrid();
        if (count($rows) !== count($tiles)) {
            throw new InvalidArgumentException("{$context} must have " . count($tiles) . ' rows.');
        }

        $grid = [];
        foreach ($tiles as $y => $tileRow) {
            $row = $rows[$y];
            if (!is_array($row) || !array_is_list($row)) {
                throw new InvalidArgumentException("{$context} row {$y} must be a zero-based list of CollisionType cases.");
            }
            if (count($row) !== count($tileRow)) {
                throw new InvalidArgumentException("{$context} row {$y} must be " . count($tileRow) . ' tiles wide.');
            }
            $grid[$y] = [];
            foreach ($row as $x => $type) {
                if (!$type instanceof CollisionType) {
                    throw new InvalidArgumentException("{$context} cell {$x}, {$y} must be a CollisionType case; got " . get_debug_type($type) . '.');
                }
                if ($type === CollisionType::PASS_THROUGH) {
                    throw new InvalidArgumentException("{$context} cell {$x}, {$y} must be resolved; PASS_THROUGH is only a legacy layer passage rule.");
                }
                // Copy scalar values so caller-owned references cannot change the snapshot.
                $grid[$y][$x] = $type->value;
            }
        }
        $this->collisionGrid = $grid;
    }

    /** @return list<list<CollisionType>> A declaration for an explicit source-preserving migration. */
    public function exportDeclaration(): array
    {
        return array_map(static fn(array $row): array => array_map(CollisionType::from(...), $row), $this->collisionGrid);
    }
}
