<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
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
    /** The tile layer setting naming the gameplay layer whose glyphs its tiles move with. */
    public const string MOVES_WITH_KEY = 'movesWith';
    /**
     * A layer may be drawn this many field cells across or down from its grid,
     * like a Tiled layer offset: half a cell lets art sit between the cells
     * its terminal footprint allows. It never moves collision or events.
     */
    public const array OFFSET_STEPS = [-0.5, 0.0, 0.5];

    /**
     * @param list<MapTileLayer> $layers In drawing order.
     * @param array<string, array{float, float}> $offsets Each offset layer's shift across and down, in field cells.
     * @param array<string, ?string> $owners The gameplay layer each tile layer belongs to, or null for none,
     *     by tile layer name ({@see resolveLayerOwners()}). A tile layer left out belongs to none.
     */
    public function __construct(public Tileset $tileset, public array $layers, public array $offsets = [],
        public array $owners = [])
    {
    }

    /**
     * Reads the map data's tile layer settings: `'tileLayers' => ['lounge' =>
     * ['offset' => [0, -0.5]]]`, an offset across and down in field cells.
     * A layer's settings may also name the gameplay layer it moves with
     * ({@see readLayersMovingWith()}); a layer without an offset has none.
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
            if (!is_array($layer) || $layer === [] || array_diff(array_keys($layer), ['offset', self::MOVES_WITH_KEY]) !== []) {
                throw new InvalidArgumentException("{$where} '{$name}' accepts an offset and the gameplay layer it "
                    . self::MOVES_WITH_KEY . '.');
            }
            if (!array_key_exists('offset', $layer)) {
                continue;
            }
            if (!is_array($layer['offset']) || !array_is_list($layer['offset']) || count($layer['offset']) !== 2) {
                throw new InvalidArgumentException("{$where} '{$name}' offset must be two numbers, across and down.");
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
     * Reads which gameplay layer each tile layer moves with when authoring
     * tools move glyphs: `'tileLayers' => ['floor' => ['movesWith' =>
     * 'buildings']]`. Moving a block of that layer's glyphs carries this
     * layer's tiles in the same cells. The setting is the explicit half of
     * the gameplay layer a tile layer belongs to ({@see resolveLayerOwners()}).
     *
     * @param list<string> $names The map's tile layer names.
     * @param list<string> $gameplayNames The map's gameplay layer names.
     * @return array<string, string> Gameplay layer names by tile layer name.
     */
    public static function readLayersMovingWith(mixed $settings, array $names, array $gameplayNames, string $displayDirectory): array
    {
        self::readLayerOffsets($settings, $names, $displayDirectory);
        $where = "Map {$displayDirectory} " . self::SETTINGS_KEY;
        $movesWith = [];
        foreach ($settings ?? [] as $name => $layer) {
            if (!array_key_exists(self::MOVES_WITH_KEY, $layer)) {
                continue;
            }
            if (!is_string($layer[self::MOVES_WITH_KEY]) || !in_array($layer[self::MOVES_WITH_KEY], $gameplayNames, true)) {
                throw new InvalidArgumentException("{$where} '{$name}' " . self::MOVES_WITH_KEY
                    . ' must name one of its gameplay layers: ' . implode(', ', $gameplayNames) . '.');
            }
            $movesWith[$name] = $layer[self::MOVES_WITH_KEY];
        }
        return $movesWith;
    }

    /**
     * Resolves the gameplay layer each tile layer belongs to: the one its
     * settings name it `movesWith`, and otherwise the gameplay layer whose
     * tileset pieces write it, when exactly one piece layer does. A tile layer
     * no piece writes, or that pieces of several layers write, belongs to
     * none. Authoring tools move a layer's tiles with its glyphs, and the
     * graphical field hides a glyph only under tiles of its own layer.
     *
     * @param list<string> $names The map's tile layer names.
     * @param list<string> $gameplayNames The map's gameplay layer names.
     * @param Tileset|null $tileset The map's tileset, or null when its pieces are unknown.
     * @return array<string, ?string> Gameplay layer names, or null, by tile layer name in the order given.
     * @throws InvalidArgumentException When the settings are invalid.
     */
    public static function resolveLayerOwners(mixed $settings, array $names, array $gameplayNames, ?Tileset $tileset,
        string $displayDirectory): array
    {
        $named = self::readLayersMovingWith($settings, $names, $gameplayNames, $displayDirectory);
        $writers = [];
        foreach ($tileset?->pieces ?? [] as $piece) {
            foreach (array_keys($piece->connects === null ? $piece->tiles : $piece->shapeTiles) as $name) {
                $writers[$name][$piece->layer] = true;
            }
        }
        $owners = [];
        foreach ($names as $name) {
            $written = count($writers[$name] ?? []) === 1 ? (string)array_key_first($writers[$name]) : null;
            $owners[$name] = $named[$name] ?? (in_array($written, $gameplayNames, true) ? $written : null);
        }
        return $owners;
    }

    /**
     * The cells whose terminal glyph the graphical field shows, by its glyph
     * fallback rule: a cell shows the glyph of the gameplay layer that owns
     * it unless a tile in that cell covers that layer, a tile of a tile layer
     * that belongs to that gameplay layer or to none. A tile whose sheet the
     * tileset cannot draw covers nothing, and a tile beside a cell never
     * covers it, even with an offset. Renderers apply the same rule; tools
     * use this to find glyphs that have no graphics yet.
     *
     * @return list<array{x: int, y: int, glyph: string, layer: string}> In row order, without blank cells.
     */
    public function getShownGlyphCells(MapLayerSet $layers, string $assetRoot): array
    {
        $usable = $this->tileset->getUsableSheets($assetRoot)['sheets'] ?? [];
        $covers = [];
        foreach ($this->layers as $layer) {
            $owner = $this->owners[$layer->name] ?? null;
            foreach ($layer->tiles as $y => $row) {
                foreach ($row as $x => $tileId) {
                    if ($tileId !== TileId::EMPTY && isset($usable[TileId::getSheet($tileId)?->value ?? ''])) {
                        $covers[$y][$x][$owner ?? ''] = true;
                    }
                }
            }
        }
        $shown = [];
        foreach ($layers->getComposedGrid() as $y => $cells) {
            foreach ($cells as $x => $cell) {
                $glyph = TerminalText::stripAnsi($cell);
                if (trim($glyph) === '') {
                    continue;
                }
                $owner = $layers->getGameplayLayerAt($x, $y)->name;
                if (!isset($covers[$y][$x][$owner]) && !isset($covers[$y][$x][''])) {
                    $shown[] = ['x' => $x, 'y' => $y, 'glyph' => $glyph, 'layer' => $owner];
                }
            }
        }
        return $shown;
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
        self::assertTilesetNamed($tilesetId, $displayDirectory);
        $files = is_dir($directory) ? (glob($directory . '/*.tiles.php') ?: []) : [];
        $sources = [];
        foreach ($files as $path) {
            $source = @file_get_contents($path);
            if ($source === false) {
                throw new InvalidArgumentException('Grid source ' . self::getLayerDisplayPath($path, $displayDirectory) . ' could not be read.');
            }
            $sources[$path] = $source;
        }
        return self::fromSources($sources, $displayDirectory, $tilesetId, $layers, $assetRoot, $settings);
    }

    /**
     * Reads a map's graphics from its tile layer sources rather than its
     * `graphics/` directory, so authoring tools can draw tile layers they
     * have not saved yet. Each source is parsed and validated exactly as
     * {@see loadFromDirectory()} reads it from disk, and never executed.
     * Null when the map names no tileset and has no tile layers.
     *
     * @param array<string, string> $sources Source bytes keyed by the layer file's path; only its file name is read.
     */
    public static function fromSources(array $sources, string $displayDirectory, mixed $tilesetId, MapLayerSet $layers,
        string $assetRoot, mixed $settings = null): ?self
    {
        if ($tilesetId === null && $sources === []) {
            return null;
        }
        self::assertTilesetNamed($tilesetId, $displayDirectory);
        $tileset = Tileset::load($assetRoot, $tilesetId);
        self::assertLayerCount(count($sources), $displayDirectory);
        $tileLayers = $orders = [];
        foreach ($sources as $path => $source) {
            $filename = basename((string)$path);
            $displayPath = self::getLayerDisplayPath($filename, $displayDirectory);
            if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
                throw new InvalidArgumentException("Tile layer {$displayPath} must be named NN.name.tiles.php.");
            }
            if (isset($orders[$matches['order']])) {
                throw new InvalidArgumentException("Tile layer {$displayPath} repeats order {$matches['order']}.");
            }
            $orders[$matches['order']] = true;
            $layer = new MapTileLayer($matches['name'], (int)$matches['order'], $displayPath, MapGridSource::parseSource($source, $displayPath));
            $layer->assertMatches($layers);
            $tileLayers[] = $layer;
        }
        usort($tileLayers, static fn(MapTileLayer $a, MapTileLayer $b): int => $a->order <=> $b->order);
        $names = array_map(static fn(MapTileLayer $layer): string => $layer->name, $tileLayers);
        $offsets = self::readLayerOffsets($settings, $names, $displayDirectory);
        $owners = self::resolveLayerOwners($settings, $names, array_values(array_map(static fn(MapLayer $layer): string => $layer->name,
            array_filter($layers->layers, static fn(MapLayer $layer): bool => !$layer->decoration))), $tileset, $displayDirectory);
        return new self($tileset, $tileLayers, $offsets, $owners);
    }

    /** @phpstan-assert string $tilesetId */
    private static function assertTilesetNamed(mixed $tilesetId, string $displayDirectory): void
    {
        if (!is_string($tilesetId)) {
            throw new InvalidArgumentException("Map {$displayDirectory} has graphics but names no tileset in its data file.");
        }
    }

    private static function assertLayerCount(int $count, string $displayDirectory): void
    {
        if ($count > self::MAX_LAYERS) {
            throw new InvalidArgumentException("Map {$displayDirectory} has more than " . self::MAX_LAYERS . ' tile layers.');
        }
    }

    private static function getLayerDisplayPath(string $path, string $displayDirectory): string
    {
        return "{$displayDirectory}/" . self::DIRECTORY . '/' . basename($path);
    }
}
