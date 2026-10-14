<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;
use voku\helper\ASCII;

final class MapCollisionResolver
{
    /**
     * A declared occupancy grid replaces glyph-derived passage, never supplements it.
     * Only an absent key selects compatibility; malformed declarations must refuse.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
     */
    public static function resolveMap(MapLayerSet $layers, array $data, array $dictionary = []): MapPhysicalOccupancy
    {
        if (array_key_exists(MapPhysicalOccupancy::DATA_KEY, $data)) {
            return new MapPhysicalOccupancy($data[MapPhysicalOccupancy::DATA_KEY], $layers);
        }
        return self::resolveLegacyOccupancy($layers, $dictionary);
    }

    /**
     * Capture the exact legacy result for an explicitly requested authoring migration.
     * This does not change sources or install the declaration in the map data.
     *
     * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
     */
    public static function resolveLegacyOccupancy(MapLayerSet $layers, array $dictionary): MapPhysicalOccupancy
    {
        $rows = array_map(static fn(array $row): array => array_map(CollisionType::from(...), $row),
            self::resolveLayers($layers, $dictionary));
        return new MapPhysicalOccupancy($rows, $layers);
    }

    /**
     * Compatibility for the existing flat, optionally string-row MapManager API.
     *
     * @param array<int, string[]|string> $tiles
     * @param array<int|string, mixed> $dictionary
     * @return int[][]
     */
    public static function resolveTiles(array $tiles, array $dictionary): array
    {
        $grid = [];
        foreach ($tiles as $row) {
            $result = [];
            foreach (is_array($row) ? $row : TerminalText::visibleSymbols($row) as $tile) {
                $glyph = ASCII::to_ascii(TerminalText::stripAnsi($tile));
                $type = $dictionary[$glyph] ?? CollisionType::SOLID;
                $result[] = $type instanceof CollisionType && $type !== CollisionType::PASS_THROUGH
                    ? $type->value : CollisionType::SOLID->value;
            }
            $grid[] = $result;
        }
        return $grid;
    }

    /** @param array<int|string, mixed> $dictionary */
    public static function validateDictionary(array $dictionary, string $context = 'Collision dictionary'): void
    {
        foreach ($dictionary as $key => $value) {
            if (is_array($value) && is_string($key)
                && preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $key) === 1) {
                foreach ($value as $glyph => $type) {
                    self::validateEntry($glyph, $type, "{$context} layer {$key}");
                }
            } else {
                self::validateEntry($key, $value, $context);
            }
        }
    }

    private static function validateEntry(int|string $key, mixed $value, string $context): void
    {
        if (TerminalText::symbolCount((string)$key) === 1 && $value instanceof CollisionType) {
            return;
        }
        $description = $value instanceof \UnitEnum ? $value::class . '::' . $value->name
            : (is_scalar($value) || $value === null ? get_debug_type($value) . '(' . var_export($value, true) . ')' : get_debug_type($value));
        throw new InvalidArgumentException(sprintf('%s: Invalid dictionary entry: %s(%s) => %s',
            $context, get_debug_type($key), var_export($key, true), $description));
    }

    /**
     * Legacy glyph-keyed compatibility adapter; new consumers use resolveMap.
     *
     * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
     * @return int[][]
     */
    public static function resolveLayers(MapLayerSet $set, array $dictionary): array
    {
        self::validateDictionary($dictionary);
        $flat = array_filter($dictionary, static fn(mixed $value): bool => $value instanceof CollisionType);
        $gameplay = [];
        foreach ($set->layers as $layer) {
            if ($layer->decoration) {
                if (is_array($dictionary[$layer->name] ?? null)) {
                    throw new InvalidArgumentException("Decoration layer {$layer->path} must not be named in the collision dictionary.");
                }
                continue;
            }
            $section = $dictionary[$layer->name] ?? [];
            $gameplay[] = [$layer, (is_array($section) ? $section : []) + $flat];
        }
        $result = [];
        foreach ($gameplay[0][0]->grid as $y => $row) {
            foreach ($row as $x => $_) {
                $result[$y][$x] = CollisionType::SOLID->value;
                for ($index = count($gameplay) - 1; $index >= 0; $index--) {
                    [$layer, $types] = $gameplay[$index];
                    $glyph = TerminalText::stripAnsi($layer->grid[$y][$x]);
                    if ($index !== 0 && $glyph === ' ') {
                        continue;
                    }
                    $type = $types[ASCII::to_ascii($glyph)] ?? CollisionType::SOLID;
                    if ($type !== CollisionType::PASS_THROUGH) {
                        $result[$y][$x] = $type->value;
                        break;
                    }
                }
            }
            $result[$y] ??= [];
        }
        return $result;
    }
}
