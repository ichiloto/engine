<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use InvalidArgumentException;

/** Authored map cells and identity, independent of an object's artwork. */
final readonly class WorldObjectPlacement
{
    public const int MAX_CELLS = 256;
    public const int MAX_CONDITIONS = 32;

    private function __construct(
        public string $id,
        public int $x,
        public int $y,
        public array $cells,
        public array $conditions,
    ) {}

    /** @param list<list<string>> $grid */
    public static function read(array $entry, array $grid): self
    {
        $id = self::requireId($entry['id'] ?? null);
        $anchor = $entry['anchor'] ?? null;
        if (!is_array($anchor) || array_diff(array_keys($anchor), ['x', 'y']) !== []
            || !is_int($anchor['x'] ?? null) || !is_int($anchor['y'] ?? null)
            || !isset($grid[$anchor['y']][$anchor['x']])) {
            throw new InvalidArgumentException("World object '{$id}' requires an anchor with integer x/y inside its map.");
        }
        $conditions = $entry['conditions'] ?? [];
        if ((array_key_exists('conditions', $entry) && $entry['conditions'] === null)
            || !is_array($conditions) || !array_is_list($conditions) || count($conditions) > self::MAX_CONDITIONS) {
            throw new InvalidArgumentException("World object '{$id}' conditions require a list of at most 32 conditions.");
        }
        WorldConditionEvaluator::validateAll($conditions, "worldObjects/{$id}/conditions");
        $cells = [[$anchor['x'], $anchor['y']]];
        if (array_key_exists('covers', $entry)) {
            $covers = $entry['covers'];
            if (!is_array($covers) || !is_array($covers['cells'] ?? null) || !array_is_list($covers['cells'])
                || $covers['cells'] === [] || count($covers['cells']) > self::MAX_CELLS) {
                throw new InvalidArgumentException('World object covers requires 1..256 explicit map cells.');
            }
            $cells = $covers['cells'];
            $seen = [];
            foreach ($cells as $cell) {
                if (!is_array($cell) || !array_is_list($cell) || count($cell) !== 2
                    || !is_int($cell[0]) || !is_int($cell[1]) || !isset($grid[$cell[1]][$cell[0]])
                    || isset($seen[$cell[1]][$cell[0]])) {
                    throw new InvalidArgumentException('World object covers cells must be distinct integer [x,y] cells inside the map.');
                }
                $seen[$cell[1]][$cell[0]] = true;
            }
        }
        return new self($id, $anchor['x'], $anchor['y'], $cells, $conditions);
    }

    /** @return array<string, self> */
    public static function readMap(array $data, array $grid): array
    {
        $entries = $data[WorldObjectDefinition::DATA_KEY] ?? [];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > WorldObjectDefinition::MAX_OBJECTS) {
            throw new InvalidArgumentException('worldObjects must be a list of at most 128 declarations.');
        }
        $placements = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) { throw new InvalidArgumentException('A world object requires a placement declaration.'); }
            $placement = self::read($entry, $grid);
            if (isset($placements[$placement->id])) { throw new InvalidArgumentException("Duplicate world object id '{$placement->id}'."); }
            $placements[$placement->id] = $placement;
        }
        return $placements;
    }

    public static function requireId(mixed $id): string
    {
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $id) !== 1) {
            throw new InvalidArgumentException('World object and variant ids must be stable lowercase identifiers of 1..64 characters.');
        }
        return $id;
    }
}
