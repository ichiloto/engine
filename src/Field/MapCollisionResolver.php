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
