<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Closure;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use InvalidArgumentException;

/** Final physical cells, independent of terminal layers and graphical resources. */
final readonly class MapPhysicalOccupancy
{
    public const string DATA_KEY = 'occupancy';
    public const string PACKED_ROWS_KEY = 'packedRows';
    private const string PACKED_CODES = '0123456789A';

    /** @var list<list<int>> The existing runtime collision-grid representation. */
    public array $collisionGrid;

    /**
     * A declaration holds enum rows or a packedRows list of ASCII collision codes.
     * Each packed code represents a CollisionType value, independent of tile glyphs.
     * Ragged maps retain their exact row lengths; missing cells are not padded.
     */
    public function __construct(mixed $rows, MapLayerSet $layers, string $context = 'Map occupancy', ?Closure $checkpoint = null)
    {
        $packed = is_array($rows) && array_keys($rows) === [self::PACKED_ROWS_KEY];
        if ($packed) { $rows = $rows[self::PACKED_ROWS_KEY]; }
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
            if ($packed) {
                $grid[$y] = self::decodePackedRow($row, count($tileRow), $y, $context);
                $checkpoint?->__invoke();
                continue;
            }
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
            $checkpoint?->__invoke();
        }
        $this->collisionGrid = $grid;
    }

    /** @return list<int> */
    private static function decodePackedRow(mixed $row, int $width, int $y, string $context): array
    {
        if (!is_string($row)) {
            throw new InvalidArgumentException("{$context} packed row {$y} must be an ASCII string.");
        }
        if (strlen($row) !== $width) {
            throw new InvalidArgumentException("{$context} packed row {$y} must be {$width} tiles wide.");
        }
        $decoded = [];
        for ($x = 0; $x < $width; $x++) {
            $value = strpos(self::PACKED_CODES, $row[$x]);
            if ($value === false || CollisionType::tryFrom($value) === null) {
                throw new InvalidArgumentException("{$context} packed cell {$x}, {$y} has an unknown collision code.");
            }
            if ($value === CollisionType::PASS_THROUGH->value) {
                throw new InvalidArgumentException("{$context} cell {$x}, {$y} must be resolved; PASS_THROUGH is only a legacy layer passage rule.");
            }
            $decoded[] = $value;
        }
        return $decoded;
    }

    /** @return array{packedRows: list<string>} */
    public function exportPackedDeclaration(): array
    {
        $rows = [];
        foreach ($this->collisionGrid as $row) {
            $packed = '';
            foreach ($row as $value) {
                $packed .= self::PACKED_CODES[$value];
            }
            $rows[] = $packed;
        }
        return [self::PACKED_ROWS_KEY => $rows];
    }

    /** @return list<list<CollisionType>> A declaration for an explicit source-preserving migration. */
    public function exportDeclaration(): array
    {
        return array_map(static fn(array $row): array => array_map(CollisionType::from(...), $row), $this->collisionGrid);
    }
}
