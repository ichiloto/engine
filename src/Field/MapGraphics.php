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

    /** @param list<MapTileLayer> $layers In drawing order. */
    public function __construct(public Tileset $tileset, public array $layers)
    {
    }

    /**
     * Reads a map's graphics, or null when it has none. Grid files are parsed
     * as literal nowdocs and never executed.
     */
    public static function loadFromDirectory(string $mapDirectory, string $displayDirectory, mixed $tilesetId,
        MapLayerSet $layers, string $assetRoot): ?self
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
        return new self($tileset, $tileLayers);
    }
}
