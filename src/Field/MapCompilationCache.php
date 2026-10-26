<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\PackedGrid;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use LogicException;
use WeakReference;

/** Immutable compilation snapshots. Current sources are checked before lookup; mutable runtime state is never cached. */
final class MapCompilationCache
{
    // Compressed snapshots survive map exit; expanded objects remain owned only by their current consumers.
    public const int MAX_BYTES = 512 * 1024;
    public const int MAX_EXPANDED_BYTES = 64 * 1024 * 1024;
    public const int MAX_ENTRIES = 32;
    /** Weak references preserve normal ownership and release; only compressed bytes survive map exit. */
    private static array $entries = [];
    private static int $bytes = 0;

    public static function find(string $key): MapLayerSet|PresentationWorld|array|null
    {
        $entry = self::$entries[$key] ?? null;
        if ($entry === null) { return null; }
        unset(self::$entries[$key]);
        self::$entries[$key] = $entry;
        if ($entry['live'] instanceof WeakReference) {
            if (($value = $entry['live']->get()) !== null) { return $value; }
        } else {
            $values = [];
            foreach ($entry['live'] as $reference) {
                $value = $reference->get();
                if ($value === null) { break; }
                $values[] = $value;
            }
            if (count($values) === count($entry['live'])) { return $values; }
        }
        $serialized = gzuncompress($entry['compressed'], self::MAX_EXPANDED_BYTES);
        if ($serialized === false) { throw new LogicException('An in-memory map compilation snapshot is corrupt.'); }
        $value = unserialize($serialized, ['allowed_classes' => [MapLayerSet::class, MapLayer::class,
            MapTileLayer::class, PackedGrid::class, PresentationWorld::class]]);
        if (!$value instanceof MapLayerSet && !$value instanceof PresentationWorld && !is_array($value)) {
            throw new LogicException('A map compilation snapshot has an invalid value type.');
        }
        self::$entries[$key]['live'] = self::createWeakReferences($value);
        return $value;
    }

    public static function store(string $key, MapLayerSet|PresentationWorld|array $value, int $expandedCharge): void
    {
        if ($expandedCharge < 0 || $expandedCharge > self::MAX_EXPANDED_BYTES) { return; }
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_EXPANDED_BYTES) { return; }
        // Balance first compilation cost against retaining a useful working set inside the release-memory budget.
        $compressed = gzcompress($serialized, 6);
        if ($compressed === false) { throw new LogicException('A map compilation snapshot could not be compressed.'); }
        $bytes = strlen($compressed);
        if ($bytes > self::MAX_BYTES) { return; }
        if (isset(self::$entries[$key])) {
            self::$bytes -= strlen(self::$entries[$key]['compressed']);
            unset(self::$entries[$key]);
        }
        while (self::$entries !== [] && (self::$bytes + $bytes > self::MAX_BYTES || count(self::$entries) >= self::MAX_ENTRIES)) {
            $first = array_key_first(self::$entries);
            self::$bytes -= strlen(self::$entries[$first]['compressed']);
            unset(self::$entries[$first]);
        }
        self::$entries[$key] = ['compressed' => $compressed, 'live' => self::createWeakReferences($value)];
        self::$bytes += $bytes;
    }

    private static function createWeakReferences(MapLayerSet|PresentationWorld|array $value): WeakReference|array
    {
        return is_array($value) ? array_map(static fn(MapTileLayer $layer): WeakReference => WeakReference::create($layer), $value)
            : WeakReference::create($value);
    }

    public static function clear(): void { self::$entries = []; self::$bytes = 0; }
}
