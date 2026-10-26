<?php

namespace Ichiloto\Engine\Core\ProjectState;

/** Bounded, detached PHP plain data shared by serialization and runtime admission. */
final class ProjectStateData
{
    public const int MAX_NAMESPACES = 64;
    public const int MAX_DEPTH = 32;
    public const int MAX_VALUES = 100_000;
    public const int MAX_BYTES = 1_048_576;

    public static function validateNamespace(string $namespace): void
    {
        if (strlen($namespace) > 128
            || preg_match('~^[a-z][a-z0-9._-]*(?:/[a-z][a-z0-9._-]*)+$~D', $namespace) !== 1) {
            throw new ProjectStateException('Project-state namespaces must be stable lowercase vendor/name paths (at most 128 bytes).');
        }
    }

    /**
     * Copying recursively deliberately strips PHP references, including shared leaf references.
     * Cycles are refused by the depth bound, without serializing arbitrary objects.
     *
     * @return array<string, array{version: int, data: array<array-key, mixed>}>
     */
    public static function copySnapshot(mixed $snapshot): array
    {
        if (!is_array($snapshot) || count($snapshot) > self::MAX_NAMESPACES) {
            throw new ProjectStateException('Project state must be an array with at most 64 namespaces.');
        }
        $values = 0;
        $bytes = 0;
        $copy = [];
        foreach ($snapshot as $namespace => $section) {
            if (!is_string($namespace)) {
                throw new ProjectStateException('Project-state namespace keys must be strings.');
            }
            self::validateNamespace($namespace);
            if (!is_array($section) || count($section) !== 2
                || !is_int($section['version'] ?? null) || $section['version'] < 1
                || !is_array($section['data'] ?? null)) {
                throw new ProjectStateException(sprintf('Project-state section "%s" requires only a positive integer version and an array data payload.', $namespace));
            }
            $bytes += strlen($namespace);
            $copy[$namespace] = [
                'version' => $section['version'],
                'data' => self::copyValue($section['data'], 0, $values, $bytes),
            ];
        }
        if (strlen(serialize($copy)) > self::MAX_BYTES) {
            throw new ProjectStateException('Project-state snapshot exceeds the 1 MiB serialized size limit.');
        }
        return $copy;
    }

    private static function copyValue(mixed $value, int $depth, int &$values, int &$bytes): mixed
    {
        if (++$values > self::MAX_VALUES || $depth > self::MAX_DEPTH) {
            throw new ProjectStateException('Project state exceeds the value-count or nesting limit.');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $bytes += is_string($key) ? strlen($key) : 8;
                self::validateByteCount($bytes);
                $copy[$key] = self::copyValue($child, $depth + 1, $values, $bytes);
            }
            return $copy;
        }
        if (is_string($value)) {
            $bytes += strlen($value);
        } elseif ($value === null || is_bool($value) || is_int($value)) {
            $bytes += 8;
        } elseif (is_float($value) && is_finite($value)) {
            $bytes += 8;
        } else {
            throw new ProjectStateException('Project state supports only arrays, strings, integers, finite floats, booleans and null; objects, resources and closures are forbidden.');
        }
        self::validateByteCount($bytes);
        return $value;
    }

    private static function validateByteCount(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new ProjectStateException('Project-state snapshot exceeds the 1 MiB size limit.');
        }
    }
}
