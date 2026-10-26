<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
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
     * tileset pieces and terrain kinds write it, when exactly one such layer does. A tile layer
     * no piece writes, or that pieces of several layers write, belongs to
     * none. Authoring tools move a layer's tiles with its glyphs, and the
     * graphical field hides a glyph only under tiles of its own layer.
     *
     * @param list<string> $names The map's tile layer names.
     * @param list<string> $gameplayNames The map's gameplay layer names.
     * @param Tileset|null $tileset The map's tileset, or null when its pieces are unknown.
     * @param bool $paintedInKinds Whether the map is painted in the tileset's terrain kinds, whose pieces then write too.
     * @return array<string, ?string> Gameplay layer names, or null, by tile layer name in the order given.
     * @throws InvalidArgumentException When the settings are invalid.
     */
    public static function resolveLayerOwners(mixed $settings, array $names, array $gameplayNames, ?Tileset $tileset,
        string $displayDirectory, bool $paintedInKinds = false): array
    {
        $named = self::readLayersMovingWith($settings, $names, $gameplayNames, $displayDirectory);
        $writers = [];
        foreach ($tileset?->getPlacements($paintedInKinds) ?? [] as $piece) {
            foreach ($tileset->getPieceTileLayers($piece) as $name) {
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
            for ($y = 0; $y < $layer->height; $y++) {
                foreach ($layer->getRowTiles($y) as $x => $tileId) {
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
     * Where a stamped piece stands whole on this map: the top-left cell of
     * every place where each of its nonzero tiles lies on its named tile layer,
     * in row order. Its `0` tiles match anything, as stamping leaves those
     * cells as they were. Only the tiles say so, never glyphs or collision, so
     * a copy partly painted over is no longer one. A connected piece, or one
     * with no nonzero tiles, is never found.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function findPieceStamps(TilesetPiece $piece): array
    {
        $stencil = self::readStencil($piece);
        if ($stencil === []) { return []; }
        $layers = $this->indexLayers();
        $stamps = [];
        $origin = $layers[array_key_first($piece->tiles)] ?? null;
        for ($y = 0; $y < ($origin?->height ?? 0); $y++) {
            for ($x = 0, $width = $origin->getRowLength($y); $x < $width; $x++) {
                if (self::matchesStencil($layers, $stencil, $x, $y)) { $stamps[] = [$x, $y]; }
            }
        }
        return $stamps;
    }

    /**
     * The copies of a stamped piece standing whole over a cell, by the rule of
     * {@see findPieceStamps()}: their top-left cells, in row order. Only the
     * origins from which the piece's footprint reaches the cell are read, so
     * an editor can ask about the cell under the pointer on any map.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function findPieceStampsCovering(TilesetPiece $piece, int $x, int $y): array
    {
        $stencil = self::readStencil($piece);
        if ($stencil === []) { return []; }
        $layers = $this->indexLayers();
        $stamps = [];
        for ($top = max(0, $y - $piece->height + 1); $top <= $y; $top++) {
            for ($left = max(0, $x - $piece->width + 1); $left <= $x; $left++) {
                if (self::matchesStencil($layers, $stencil, $left, $top)) { $stamps[] = [$left, $top]; }
            }
        }
        return $stamps;
    }

    /** @return array<string, MapTileLayer> The canonical integer layers by name; string entry grids are an authoring export. */
    private function indexLayers(): array
    {
        $layers = [];
        foreach ($this->layers as $layer) { $layers[$layer->name] = $layer; }
        return $layers;
    }

    /**
     * A stamped piece's nonzero tiles, each with its layer and its place from
     * the top-left; empty for a connected piece or one with no nonzero tiles.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int}>
     */
    private static function readStencil(TilesetPiece $piece): array
    {
        if ($piece->connects !== null) { return []; }
        $stencil = [];
        foreach ($piece->tiles as $name => $rows) {
            foreach ($rows as $dy => $entries) {
                foreach ($entries as $dx => $tile) {
                    if ($tile === '0') { continue; }
                    $stencil[] = [$name, $dx, $dy, (int) $tile];
                }
            }
        }
        return $stencil;
    }

    /**
     * @param array<string, MapTileLayer> $layers
     * @param list<array{0: string, 1: int, 2: int, 3: int}> $stencil
     */
    private static function matchesStencil(array $layers, array $stencil, int $x, int $y): bool
    {
        foreach ($stencil as [$name, $dx, $dy, $tile]) {
            if (($layers[$name] ?? null)?->getTileAt($x + $dx, $y + $dy) !== $tile) { return false; }
        }
        return true;
    }

    /**
     * Reads a map's graphics, or null when it has none. Grid files are parsed
     * as literal nowdocs and never executed.
     * A caller-owned tileset may supply the current unsaved definition; its
     * identity must match the map's declared tileset.
     */
    public static function loadFromDirectory(string $mapDirectory, string $displayDirectory, mixed $tilesetId,
        MapLayerSet $layers, string $assetRoot, mixed $settings = null, ?Tileset $tileset = null, bool $paintedInKinds = false): ?self
    {
        $directory = $mapDirectory . DIRECTORY_SEPARATOR . self::DIRECTORY;
        if ($tilesetId === null && !is_dir($directory) && $tileset === null) {
            return null;
        }
        self::assertTilesetNamed($tilesetId, $displayDirectory);
        return self::fromSources(self::readLayerSources($directory, $displayDirectory), $displayDirectory,
            $tilesetId, $layers, $assetRoot, $settings, $tileset, $paintedInKinds);
    }

    /**
     * Reads the same validated layer declarations without requiring optional tileset assets.
     * Missing graphics directories have unknown declarations, not a proven empty layer list.
     *
     * @return list<MapTileLayer>|null
     */
    public static function readLayersFromDirectory(string $mapDirectory, string $displayDirectory, MapLayerSet $layers): ?array
    {
        $directory = $mapDirectory . DIRECTORY_SEPARATOR . self::DIRECTORY;
        return is_dir($directory)
            ? self::readLayersFromSources(self::readLayerSources($directory, $displayDirectory), $displayDirectory, $layers)
            : null;
    }

    /** @return array<string, string> */
    private static function readLayerSources(string $directory, string $displayDirectory): array
    {
        $files = is_dir($directory) ? glob($directory . '/*.tiles.php') : [];
        if ($files === false) {
            throw new InvalidArgumentException("Map {$displayDirectory}/" . self::DIRECTORY . ' tile layers could not be read.');
        }
        $sources = [];
        foreach ($files as $path) {
            $source = @file_get_contents($path);
            if ($source === false) {
                throw new InvalidArgumentException('Grid source ' . self::getLayerDisplayPath($path, $displayDirectory) . ' could not be read.');
            }
            $sources[$path] = $source;
        }
        return $sources;
    }

    /**
     * Reads a map's graphics from its tile layer sources rather than its
     * `graphics/` directory, so authoring tools can draw tile layers they
     * have not saved yet. Each source is parsed and validated exactly as
     * {@see loadFromDirectory()} reads it from disk, and never executed.
     * Null when the map names no tileset and has no tile layers.
     * A supplied tileset is used unchanged, without reading its file from
     * disk, and must have the identity the map declares.
     *
     * @param array<string, string> $sources Source bytes keyed by the layer file's path; only its file name is read.
     */
    public static function fromSources(array $sources, string $displayDirectory, mixed $tilesetId, MapLayerSet $layers,
        string $assetRoot, mixed $settings = null, ?Tileset $tileset = null, bool $paintedInKinds = false): ?self
    {
        if ($tileset !== null && $tileset->id !== $tilesetId) {
            throw new InvalidArgumentException("Map {$displayDirectory} declares tileset " . var_export($tilesetId, true)
                . " but received tileset '{$tileset->id}'.");
        }
        if ($tilesetId === null && $sources === []) {
            return null;
        }
        self::assertTilesetNamed($tilesetId, $displayDirectory);
        $tileset ??= Tileset::load($assetRoot, $tilesetId);
        $tileLayers = self::readLayersFromSources($sources, $displayDirectory, $layers);
        $names = array_map(static fn(MapTileLayer $layer): string => $layer->name, $tileLayers);
        $offsets = self::readLayerOffsets($settings, $names, $displayDirectory);
        $owners = self::resolveLayerOwners($settings, $names, array_values(array_map(static fn(MapLayer $layer): string => $layer->name,
            array_filter($layers->layers, static fn(MapLayer $layer): bool => !$layer->decoration))), $tileset, $displayDirectory,
            $paintedInKinds);
        return new self($tileset, $tileLayers, $offsets, $owners);
    }

    /**
     * @param array<string, string> $sources
     * @return list<MapTileLayer>
     */
    public static function readLayersFromSources(array $sources, string $displayDirectory, MapLayerSet $layers): array
    {
        self::assertLayerCount(count($sources), $displayDirectory);
        $key = 'tiles:' . hash('sha256', serialize([$sources, $displayDirectory, array_map(count(...), $layers->getComposedGrid())]));
        $cached = MapCompilationCache::find($key);
        if (is_array($cached)) { return $cached; }

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
        $charge = array_sum(array_map(strlen(...), $sources));
        foreach ($tileLayers as $layer) { $charge += $layer->width * $layer->height * 4; }
        MapCompilationCache::store($key, $tileLayers, $charge);
        return $tileLayers;
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
