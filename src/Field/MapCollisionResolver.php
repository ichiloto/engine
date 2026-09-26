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
                $result[$y][$x] = CollisionType::SOLID->value;
                for ($index = count($gameplay) - 1; $index >= 0; $index--) {
                    [$layer, $types] = $gameplay[$index];
                    $cell = $layer->glyphs[$y][$x];
                    if ($index !== 0 && MapCell::isBlank($cell)) {
                        continue;
                    }
                    $type = self::resolveCell($cell, $types);
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

    /**
     * A cell's kind from its characters, ignoring spaces within it: solid when
     * any character is solid, so pairing never opens a wall; otherwise the first
     * kind other than none, left to right. A blank cell uses the space entry.
     *
     * @param array<int|string, CollisionType> $types
     */
    public static function resolveCell(string $cell, array $types): CollisionType
    {
        $kinds = [];
        foreach (MapCell::getCharacters($cell) as $character) {
            if (trim($character) === '') {
                continue;
            }
            $kind = $types[ASCII::to_ascii($character)] ?? CollisionType::SOLID;
            if ($kind === CollisionType::SOLID) {
                return $kind;
            }
            $kinds[] = $kind;
        }
        if ($kinds === []) {
            return $types[' '] ?? CollisionType::SOLID;
        }
        $kinds = array_values(array_filter($kinds, static fn(CollisionType $kind): bool => $kind !== CollisionType::PASS_THROUGH));
        if ($kinds === []) {
            return CollisionType::PASS_THROUGH;
        }
        foreach ($kinds as $kind) {
            if ($kind !== CollisionType::NONE) {
                return $kind;
            }
        }
        return CollisionType::NONE;
    }
}
