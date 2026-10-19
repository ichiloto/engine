<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use InvalidArgumentException;

/** Named physical-map territories, independent of artwork, collision and discovery. */
final readonly class MapAreaCatalog
{
    public const string ID_PATTERN = '/\A[a-z][a-z0-9._-]{0,63}\z/';
    public const array DATA_FIELDS = ['id', 'area'];
    public const int MAX_AREAS = 256;

    /** @param array<string, CellArea> $areas */
    private function __construct(public array $areas) {}

    /** Read the sole geometry owner; reject invalid bounds before expanding rectangles. */
    public static function readMap(array $data, array $grid): self
    {
        $entries = array_key_exists('areas', $data) ? $data['areas'] : [];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_AREAS) {
            throw new InvalidArgumentException('Map areas require a list of at most 256 declarations.');
        }
        if ($entries === []) { return new self([]); }
        if (!array_is_list($grid)) {
            throw new InvalidArgumentException('Map area geometry requires a list of physical map rows.');
        }
        $rowWidths = [];
        foreach ($grid as $row) {
            if (!is_array($row) || !array_is_list($row)) {
                throw new InvalidArgumentException('Map area geometry requires rows of physical map cells.');
            }
            $rowWidths[] = count($row);
        }
        $areas = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), self::DATA_FIELDS) !== []) {
                throw new InvalidArgumentException('A map area accepts id and area.');
            }
            $id = $entry['id'] ?? null;
            if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1 || isset($areas[$id])) {
                throw new InvalidArgumentException('Map areas require unique stable lowercase ids of 1..64 characters.');
            }
            $shape = $entry['area'] ?? null;
            if (!is_array($shape)) { throw new InvalidArgumentException("Map area '{$id}' requires an area."); }
            if (array_key_exists('cells', $shape)) {
                if (array_keys($shape) !== ['cells'] || !is_array($shape['cells']) || !array_is_list($shape['cells'])
                    || count($shape['cells']) > array_sum($rowWidths)) {
                    throw new InvalidArgumentException("Map area '{$id}' requires a bounded list of cells.");
                }
                $area = CellArea::fromArray($shape, "Map area '{$id}'");
                if (count($area->cells) !== count($shape['cells'])) {
                    throw new InvalidArgumentException("Map area '{$id}' contains duplicate cells.");
                }
            } else {
                if (count($shape) !== 4 || array_diff(array_keys($shape), ['x', 'y', 'width', 'height']) !== []
                    || !is_int($shape['x'] ?? null) || !is_int($shape['y'] ?? null)
                    || !is_int($shape['width'] ?? null) || !is_int($shape['height'] ?? null)
                    || $shape['x'] < 0 || $shape['y'] < 0 || $shape['width'] <= 0 || $shape['height'] <= 0
                    || $shape['y'] >= count($rowWidths) || $shape['height'] > count($rowWidths) - $shape['y']) {
                    throw new InvalidArgumentException("Map area '{$id}' requires a rectangle within the physical map.");
                }
                foreach (array_slice($rowWidths, $shape['y'], $shape['height']) as $width) {
                    if ($shape['x'] >= $width || $shape['width'] > $width - $shape['x']) {
                        throw new InvalidArgumentException("Map area '{$id}' extends outside the physical map.");
                    }
                }
                $area = CellArea::fromArray($shape, "Map area '{$id}'");
            }
            foreach ($area->cells as [$x, $y]) {
                if ($y < 0 || $x < 0 || !isset($rowWidths[$y]) || $x >= $rowWidths[$y]) {
                    throw new InvalidArgumentException("Map area '{$id}' extends outside the physical map.");
                }
            }
            $areas[$id] = $area;
        }
        return new self($areas);
    }

    public function getArea(string $id): CellArea
    {
        return $this->areas[$id] ?? throw new InvalidArgumentException("Map area '{$id}' was not found.");
    }
}
