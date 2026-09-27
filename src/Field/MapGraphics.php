<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use InvalidArgumentException;

/**
 * A map's graphical presentation: its tileset and ordered tile layers from
 * `graphics/`. Independent of terminal meaning: it never affects geometry,
 * collision, events or saves.
 */
final readonly class MapGraphics
{
    public const string DIRECTORY = 'graphics';
    public const string FILENAME_PATTERN = '/\A(?<order>[0-9]{2})\.(?<name>[A-Za-z][A-Za-z0-9_-]*)\.tiles\.php\z/';
    public const int MAX_LAYERS = 16;
    /** The map data key for per-layer settings, like a Tiled layer's properties. */
    public const string SETTINGS_KEY = 'tileLayers';
    /**
     * A layer may be drawn this many field cells across or down from its grid,
     * like a Tiled layer offset: half a cell lets art sit between the cells
     * its terminal footprint allows. It never moves collision or events.
     */
    public const array OFFSET_STEPS = [-0.5, 0.0, 0.5];

    /**
     * @param list<MapTileLayer> $layers In drawing order.
     * @param array<string, array{float, float}> $offsets Each offset layer's shift across and down, in field cells.
     */
    public function __construct(public Tileset $tileset, public array $layers, public array $offsets = [])
    {
    }

    /**
     * Reads the map data's tile layer settings: `'tileLayers' => ['lounge' =>
     * ['offset' => [0, -0.5]]]`, an offset across and down in field cells.
     *
     * @param list<string> $names The map's tile layer names.
     * @return array<string, array{float, float}>
     */
    public static function readLayerOffsets(mixed $settings, array $names, string $displayDirectory): array
    {
        if ($settings === null) {
            return [];
        }
        $where = "Map {$displayDirectory} " . self::SETTINGS_KEY;
        if (!is_array($settings)) {
            throw new InvalidArgumentException("{$where} must map tile layer names to their settings.");
        }
        $offsets = [];
        foreach ($settings as $name => $layer) {
            if (!in_array($name, $names, true)) {
                throw new InvalidArgumentException("{$where} names '{$name}', which is not one of its tile layers.");
            }
            if (!is_array($layer) || array_keys($layer) !== ['offset'] || !is_array($layer['offset'])
                || !array_is_list($layer['offset']) || count($layer['offset']) !== 2) {
                throw new InvalidArgumentException("{$where} '{$name}' accepts only an offset of two numbers, across and down.");
            }
            $offset = [];
            foreach ($layer['offset'] as $value) {
                if ((!is_int($value) && !is_float($value)) || !in_array((float)$value, self::OFFSET_STEPS, true)) {
                    throw new InvalidArgumentException("{$where} '{$name}' offsets by -0.5, 0 or 0.5 field cells on each axis.");
                }
                $offset[] = (float)$value;
            }
            $offsets[$name] = [$offset[0], $offset[1]];
        }
        return $offsets;
    }

    /**
     * Reads a map's graphics, or null when it has none. Grid files are parsed
     * as literal nowdocs and never executed.
     */
    public static function loadFromDirectory(string $mapDirectory, string $displayDirectory, mixed $tilesetId,
        MapLayerSet $layers, string $assetRoot, mixed $settings = null): ?self
    {
        $directory = $mapDirectory . DIRECTORY_SEPARATOR . self::DIRECTORY;
        if ($tilesetId === null && !is_dir($directory)) {
            return null;
        }
        if (!is_string($tilesetId)) {
            throw new InvalidArgumentException("Map {$displayDirectory} has graphics but names no tileset in its data file.");
        }
        $tileset = Tileset::load($assetRoot, $tilesetId);
        $files = is_dir($directory) ? (glob($directory . '/*.tiles.php') ?: []) : [];
        if (count($files) > self::MAX_LAYERS) {
            throw new InvalidArgumentException("Map {$displayDirectory} has more than " . self::MAX_LAYERS . ' tile layers.');
        }
        $tileLayers = $orders = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $displayPath = "{$displayDirectory}/" . self::DIRECTORY . "/{$filename}";
            if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
                throw new InvalidArgumentException("Tile layer {$displayPath} must be named NN.name.tiles.php.");
            }
            if (isset($orders[$matches['order']])) {
                throw new InvalidArgumentException("Tile layer {$displayPath} repeats order {$matches['order']}.");
            }
            $orders[$matches['order']] = true;
            $layer = new MapTileLayer($matches['name'], (int)$matches['order'], $displayPath, MapGridSource::readFile($path, $displayPath));
            $layer->assertMatches($layers);
            $tileLayers[] = $layer;
        }
        usort($tileLayers, static fn(MapTileLayer $a, MapTileLayer $b): int => $a->order <=> $b->order);
        $offsets = self::readLayerOffsets($settings, array_map(static fn(MapTileLayer $layer): string => $layer->name,
            $tileLayers), $displayDirectory);
        return new self($tileset, $tileLayers, $offsets);
    }
}
