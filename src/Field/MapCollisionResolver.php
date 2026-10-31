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
     * A map painted in terrain kinds ({@see MapTerrainKinds}) is resolved with
     * its kinds' dictionary sections, and only with them.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
     * @throws \LogicException When the kinds supplied are not the ones the map is painted in.
     */
    public static function resolveMap(MapLayerSet $layers, array $data, array $dictionary = [], ?MapTerrainKinds $kinds = null): MapPhysicalOccupancy
    {
        MapTerrainKinds::assertSupplied($data, $kinds, 'Collision');
        if (array_key_exists(MapPhysicalOccupancy::DATA_KEY, $data)) {
            return new MapPhysicalOccupancy($data[MapPhysicalOccupancy::DATA_KEY], $layers);
        }
        return self::resolveLegacyOccupancy($layers, $dictionary, $kinds);
    }

    /**
     * A project map's collision as the game resolves it: with the terrain
     * kinds of the tileset it names when it is painted in them, read from the
     * project's assets, so a caller never resolves a kind map without them.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
     * @throws InvalidArgumentException When the map is painted in kinds whose tileset cannot be read.
     */
    public static function resolveFromAssets(string $assetRoot, MapLayerSet $layers, array $data, array $dictionary = []): MapPhysicalOccupancy
    {
        return self::resolveMap($layers, $data, $dictionary, MapTerrainKinds::loadForMap($assetRoot, $data));
    }

    /**
     * Capture the exact legacy result for an explicitly requested authoring migration.
     * This does not change sources or install the declaration in the map data.
     * Pass the map's terrain kinds when it is painted in them, null otherwise.
     *
     * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
     */
    public static function resolveLegacyOccupancy(MapLayerSet $layers, array $dictionary, ?MapTerrainKinds $kinds): MapPhysicalOccupancy
    {
        $rows = array_map(static fn(array $row): array => array_map(CollisionType::from(...), $row),
            self::resolveLayers($layers, $kinds?->composeDictionary($dictionary) ?? $dictionary));
        return new MapPhysicalOccupancy($rows, $layers);
    }

    /**
     * The collision a dictionary gives a plain glyph: its own entry, then the
     * entry of its ASCII transliteration, then solid. Exact entries come first
     * so glyphs beyond ASCII, such as a tree's `▲` or a kind's `♣`, are read
     * as authored instead of as the empty transliteration they share.
     *
     * @param array<int|string, mixed> $types Glyph entries.
     */
    public static function lookUp(array $types, string $glyph): CollisionType
    {
        $type = $types[$glyph] ?? $types[ASCII::to_ascii($glyph)] ?? null;
        return $type instanceof CollisionType ? $type : CollisionType::SOLID;
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
                $type = self::lookUp($dictionary, TerminalText::stripAnsi($tile));
                $result[] = $type !== CollisionType::PASS_THROUGH ? $type->value : CollisionType::SOLID->value;
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
        $base = $gameplay[0][0];
        // Each layer's glyphs are read a row at a time, and each glyph is looked up once per layer.
        $typeOf = [];
        for ($y = 0; $y < $base->height; $y++) {
            $glyphs = array_map(static fn(array $entry): array => $entry[0]->getRowGlyphs($y), $gameplay);
            for ($x = 0, $width = $base->getRowLength($y); $x < $width; $x++) {
                $result[$y][$x] = CollisionType::SOLID->value;
                for ($index = count($gameplay) - 1; $index >= 0; $index--) {
                    $glyph = $glyphs[$index][$x] ?? ' ';
                    if ($index !== 0 && $glyph === ' ') {
                        continue;
                    }
                    $type = $typeOf[$index][$glyph] ??= self::lookUp($gameplay[$index][1], $glyph);
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
