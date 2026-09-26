<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;
use voku\helper\ASCII;

final class MapCollisionResolver
{
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
        foreach ($gameplay[0][0]->glyphs as $y => $row) {
            foreach ($row as $x => $_) {
                $kinds = [];
                for ($column = 0; $column < MapCell::COLUMNS; $column++) {
                    $kinds[] = self::resolveColumn($gameplay, $x, $y, $column);
                }
                $result[$y][$x] = self::combineColumns($kinds)->value;
            }
            $result[$y] ??= [];
        }
        return $result;
    }

    /**
     * One column of a cell, resolved as terminal layers always were: walk the
     * gameplay layers from the top, skipping upper spaces and PASS_THROUGH.
     *
     * @param list<array{MapLayer, array<int|string, CollisionType>}> $gameplay
     */
    private static function resolveColumn(array $gameplay, int $x, int $y, int $column): CollisionType
    {
        for ($index = count($gameplay) - 1; $index >= 0; $index--) {
            [$layer, $types] = $gameplay[$index];
            $character = MapCell::getColumnCharacter($layer->glyphs[$y][$x], $column);
            if ($index !== 0 && trim($character) === '') {
                continue;
            }
            $kind = self::resolveCharacter($character, $types);
            if ($kind !== CollisionType::PASS_THROUGH) {
                return $kind;
            }
        }
        return CollisionType::SOLID;
    }

    /**
     * A single layer's cell: each column's character through the dictionary,
     * then combined.
     *
     * @param array<int|string, CollisionType> $types
     */
    public static function resolveCell(string $cell, array $types): CollisionType
    {
        $kinds = [];
        for ($column = 0; $column < MapCell::COLUMNS; $column++) {
            $kinds[] = self::resolveCharacter(MapCell::getColumnCharacter($cell, $column), $types);
        }
        return in_array(CollisionType::PASS_THROUGH, $kinds, true) && count(array_unique($kinds, SORT_REGULAR)) === 1
            ? CollisionType::PASS_THROUGH : self::combineColumns($kinds);
    }

    /** @param array<int|string, CollisionType> $types */
    private static function resolveCharacter(string $character, array $types): CollisionType
    {
        return trim($character) === '' ? ($types[' '] ?? CollisionType::SOLID)
            : ($types[ASCII::to_ascii($character)] ?? CollisionType::SOLID);
    }

    /**
     * A cell is solid when either column is, so pairing never opens a wall;
     * otherwise it takes the first kind other than none, left to right.
     *
     * @param list<CollisionType> $kinds
     */
    private static function combineColumns(array $kinds): CollisionType
    {
        if (in_array(CollisionType::SOLID, $kinds, true)) {
            return CollisionType::SOLID;
        }
        foreach ($kinds as $kind) {
            if ($kind !== CollisionType::NONE && $kind !== CollisionType::PASS_THROUGH) {
                return $kind;
            }
        }
        return CollisionType::NONE;
    }
}
