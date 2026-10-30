<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Core\PackedGrid;
use Ichiloto\Engine\Field\MapCompilationCache;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\CornerShape;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilePiece;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable, map-owned upload. Camera movement never visits its cells in PHP.
 * One wire cell is one terminal cell, drawn in the field as one
 * FieldViewport::TILE_SIZE square. A map with graphics also carries its
 * tileset: the sheets, and a catalog of the tile identities it uses composed
 * into generic pieces, so the renderer needs no RPG Maker knowledge. Each
 * cell shows one whole tile: an autotile composed for the cell from its
 * neighbours, or a plain tile. Tile layers draw in the policy's map bands
 * ({@see PresentationLayerPolicy::getTileLayerOrder()}): below characters at
 * -100 + NN, their `above` tiles at 900 + NN, all below field guidance. A
 * tile layer that belongs to a gameplay layer covers only that layer's
 * glyphs ({@see getOperations()}). A tileset with shadows
 * adds, for a renderer that negotiated them, the bands its raised tiles cast
 * on the cells to their right: derived from the tiles here on every upload,
 * never authored or stored.
 *
 * The world keeps its upload compactly: each distinct wire cell once, its
 * rows as packed indices ({@see PackedGrid}), and each tile row as packed
 * column and tile pairs. {@see getOperations()} builds the operations for
 * each upload, sharing equal cells, so a world map is held in bytes per cell
 * and its per-cell arrays exist only while one upload is being sent.
 */
final class PresentationWorld
{
    public const int MAX_LAYERS = 64;
    public const int MAX_CELLS = 1048576;
    public const int MAX_EXTENT = 16384;
    public const int MAX_SOURCE_BYTES = 67108864;
    public const int LAYER_SOURCE_BYTES = 8192;
    public const int CELL_SOURCE_BYTES = 64;
    public const int PIECE_SOURCE_BYTES = 32;
    public const int TILE_CELL_SOURCE_BYTES = 16;
    public const int MAX_CATALOG_TILES = 8192;
    public const int MAX_SHEETS = 16;

    /**
     * @var list<array<string, mixed>> The upload every renderer accepts, without tile covers or shadows, built
     *   on each read. A sender streams it instead ({@see streamOperations()}).
     */
    public array $operations {
        get => $this->getOperations(false);
    }

    /**
     * @param array<string, mixed> $definition The world's `put` value, without tile covers or shadows.
     * @param list<array{glyph: string, foreground: ?array, background: ?array, ownerLayerId: string}> $wireCells Each distinct wire cell.
     * @param PackedGrid $rowCells Each row's cells, as indices into $wireCells.
     * @param array<string, array<int, string>> $tileRows Each tile layer's rows, in upload order: each row's packed column and tile pairs.
     * @param array<string, string> $tileCovers The gameplay layer id each covering tile layer id belongs to.
     * @param array{tiles: list<array<string, mixed>>, layers: list<array<string, mixed>>, rows: array<string, array<int, string>>}|null $tileShadows
     *   The shadow catalog tiles, layers and rows, sent only with tile shadows.
     */
    private function __construct(public readonly string $id, private readonly array $definition,
        private readonly array $wireCells, private readonly PackedGrid $rowCells, private readonly array $tileRows,
        public readonly array $textLayerIds, public readonly int $estimatedSourceBytes, public readonly bool $animated = false,
        public readonly array $tileCovers = [], private readonly ?array $tileShadows = null) {}

    /**
     * The operations that upload this world, built for this upload. With tile
     * covers, for a renderer that negotiated tile_covers, each tile layer that
     * belongs to a gameplay layer names it as its `coversLayerId`: its tiles
     * hide only that layer's glyphs. Without, every tile hides the glyph of
     * its cell, as before.
     *
     * With tile shadows, for a renderer that negotiated tile_shadows, the
     * shadow bands follow: their fill tiles after the catalog, so no other
     * tile's index moves, and one `shadows` layer per casting tile layer with
     * that layer's number. Layers of equal number paint in the order listed,
     * so each band paints over the tiles of its own and earlier layers and
     * under later layers and every character. A shadows layer never hides a
     * glyph. Without, nothing of the shadows is sent.
     *
     * Equal tile cells share one array across the list; a caller's edits stay
     * its own by copy-on-write.
     *
     * @return list<array<string, mixed>>
     */
    public function getOperations(bool $tileCovers, bool $tileShadows = false): array
    {
        return iterator_to_array($this->yieldOperations($tileCovers, $tileShadows, true), false);
    }

    /**
     * The same operations as {@see getOperations()}, built one at a time as
     * the caller takes them, so a sender holds only what it is sending.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function streamOperations(bool $tileCovers, bool $tileShadows = false, bool $rowRuns = false): Generator
    {
        return $this->yieldOperations($tileCovers, $tileShadows, false, $rowRuns);
    }

    /** @return Generator<int, array<string, mixed>> */
    private function yieldOperations(bool $tileCovers, bool $tileShadows, bool $shareCells, bool $rowRuns = false): Generator
    {
        $definition = $this->definition;
        if ($tileCovers && $this->tileCovers !== []) {
            $definition['layers'] = array_map(fn(array $layer): array => isset($this->tileCovers[$layer['id']])
                ? [...$layer, 'coversLayerId' => $this->tileCovers[$layer['id']]] : $layer, $definition['layers']);
        }
        $shadows = $tileShadows ? $this->tileShadows : null;
        if ($shadows !== null) {
            $definition['layers'] = [...$definition['layers'], ...$shadows['layers']];
            $definition['tileset']['tiles'] = [...$definition['tileset']['tiles'], ...$shadows['tiles']];
        }
        yield ['op' => 'put', 'kind' => 'world', 'id' => $this->id, 'value' => $definition];
        for ($y = 0; $y < $this->rowCells->height; $y++) {
            $indices = $this->rowCells->getRowValues($y);
            $cells = array_map(fn(int $index): array => $this->wireCells[$index], $indices);
            $runs = [];
            if ($rowRuns) {
                foreach ($indices as $index) {
                    $last = count($runs) - 1;
                    if ($last >= 0 && $runs[$last]['cell'] === $this->wireCells[$index]) { $runs[$last]['count']++; }
                    else { $runs[] = ['count' => 1, 'cell' => $this->wireCells[$index]]; }
                }
            }
            // Run encoding is optional per row: alternating content keeps its smaller ordinary form.
            $compact = $rowRuns && strlen(json_encode($runs, JSON_THROW_ON_ERROR)) < strlen(json_encode($cells, JSON_THROW_ON_ERROR));
            yield ['op' => $compact ? 'worldRowRuns' : 'worldRows', 'id' => $this->id,
                'rows' => [['row' => $y, $compact ? 'runs' : 'cells' => $compact ? $runs : $cells]]];
        }
        $cells = [];
        foreach ([...$this->tileRows, ...($shadows['rows'] ?? [])] as $layerId => $tileRows) {
            foreach ($tileRows as $row => $pairs) {
                $values = unpack('v*', $pairs);
                $wire = [];
                for ($index = 1, $count = count($values); $index < $count; $index += 2) {
                    $cell = ['column' => $values[$index], 'tile' => $values[$index + 1]];
                    $wire[] = $shareCells ? ($cells[$values[$index] << 16 | $values[$index + 1]] ??= $cell) : $cell;
                }
                $runs = [];
                if ($rowRuns) {
                    foreach ($wire as $cell) {
                        $last = count($runs) - 1;
                        if ($last >= 0 && $runs[$last]['tile'] === $cell['tile']
                            && $runs[$last]['column'] + $runs[$last]['count'] === $cell['column']) { $runs[$last]['count']++; }
                        else { $runs[] = [...$cell, 'count' => 1]; }
                    }
                }
                $compact = $rowRuns && strlen(json_encode($runs, JSON_THROW_ON_ERROR)) < strlen(json_encode($wire, JSON_THROW_ON_ERROR));
                yield ['op' => $compact ? 'worldTileRuns' : 'worldTiles', 'id' => $this->id, 'layerId' => $layerId,
                    'rows' => [['row' => $row, $compact ? 'runs' : 'cells' => $compact ? $runs : $wire]]];
            }
        }
    }

    public static function getFromLayers(MapLayerSet $layers, string $id = 'map', ?MapGraphics $graphics = null,
        string $assetRoot = '', array $coverage = [], array $terminalCells = [], array $tileCells = []): self
    {
        // Every input is immutable. Artwork bytes are cache invalidation facts, never game identity or admission gates.
        $facts = [];
        $art = $graphics?->tileset->sheets ?? [];
        foreach ($graphics?->tileset->kinds ?? [] as $kind) {
            if (is_string($kind->art['corners'] ?? null)) { $art[] = $kind->art['corners']; }
        }
        foreach (array_unique($art) as $asset) {
            try {
                PngAssetPreflight::inspect($assetRoot, $asset);
                $facts[$asset] = hash_file('sha256', $assetRoot . DIRECTORY_SEPARATOR . $asset);
            } catch (RuntimeException $error) { $facts[$asset] = ['unavailable' => $error->getMessage()]; }
        }
        $key = 'world:' . hash('sha256', serialize([$layers, $id, $graphics, $assetRoot, $coverage, $terminalCells, $tileCells, $facts,
            TerminalCapabilities::supportsCompositeEmoji()]));
        if (($cached = MapCompilationCache::find($key)) instanceof self) { return $cached; }
        $world = self::compileFromLayers($layers, $id, $graphics, $assetRoot, $coverage, $terminalCells, $tileCells);
        MapCompilationCache::store($key, $world, $world->estimatedSourceBytes);
        return $world;
    }

    private static function compileFromLayers(MapLayerSet $layers, string $id, ?MapGraphics $graphics,
        string $assetRoot, array $coverage, array $terminalCells, array $tileCells): self
    {
        $grid = $layers->getComposedGrid();
        foreach ($terminalCells as $y => $row) {
            foreach ($row as $x => $cell) {
                if (!isset($grid[$y][$x])) { throw new InvalidArgumentException('Terminal presentation cell lies outside its map.'); }
                if (!is_string($cell) || TerminalText::symbolCount($cell) !== 1 || TerminalText::displayWidth($cell) !== 1) {
                    throw new InvalidArgumentException('Terminal presentation requires one styled cell per map position.');
                }
                $grid[$y][$x] = $cell;
            }
        }
        $projectedOwners = [];
        // Recompose only explicitly owned graphical cells. Authored layers and gameplay never change.
        foreach ($coverage['glyphs'] ?? [] as $rows) {
            foreach ($rows as $y => $cells) {
                foreach ($cells as $x => $_) {
                    $cell = ' ';
                    $owner = $layers->getGameplayLayerAt($x, $y);
                    foreach ($layers->layers as $layer) {
                        if ($layer->decoration || isset($coverage['glyphs'][$layer->name][$y][$x])) { continue; }
                        if (($layer->getGlyphAt($x, $y) ?? ' ') !== ' ') {
                            $cell = $layer->getCellAt($x, $y);
                            $owner = $layer;
                        }
                    }
                    $grid[$y][$x] = $cell;
                    $projectedOwners[$y][$x] = $owner;
                }
            }
        }
        $height = count($grid);
        $width = max(array_map(count(...), $grid) ?: [0]);
        if ($height < 1 || $width < 1 || $height > self::MAX_EXTENT || $width > self::MAX_EXTENT
            || $width * $height > self::MAX_CELLS || count($layers->layers) > self::MAX_LAYERS) {
            throw new InvalidArgumentException('Retained world exceeds the bounded layer or cell budget.');
        }
        $metadata = $styled = [];
        $estimatedBytes = count($layers->layers) * self::LAYER_SOURCE_BYTES;
        // Each cell's owning layer id, a row at a time: its gameplay owner unless a graphical cover projected another.
        $readOwnerIds = static function (int $y) use ($layers, $projectedOwners): array {
            $ids = array_map(PresentationLayerPolicy::getMapLayerId(...), $layers->getRowGameplayLayers($y));
            foreach ($projectedOwners[$y] ?? [] as $x => $owner) { $ids[$x] = PresentationLayerPolicy::getMapLayerId($owner); }
            return $ids;
        };
        // Match the native retained-source charge before allocating per-cell wire arrays.
        foreach ($grid as $y => $cells) {
            $ownerIds = $readOwnerIds($y);
            foreach ($cells as $x => $cell) {
                $styled[$cell] ??= self::getWireCell($cell);
                $estimatedBytes += self::CELL_SOURCE_BYTES + strlen($styled[$cell]['glyph']) + strlen($ownerIds[$x]);
            }
        }
        if ($estimatedBytes > self::MAX_SOURCE_BYTES) {
            throw new InvalidArgumentException('Retained world exceeds the source-memory budget; use retained screen text.');
        }
        foreach ($layers->layers as $layer) {
            $metadata[] = ['id' => PresentationLayerPolicy::getMapLayerId($layer),
                'layer' => PresentationLayerPolicy::getMapLayerOrder($layer),
                'kind' => $layer->decoration ? 'decoration' : 'gameplay'];
        }
        $tiles = $graphics === null ? null : self::getTilePresentation($graphics, $layers, $assetRoot, $coverage['tiles'] ?? [], $tileCells);
        if ($tiles !== null) {
            $metadata = [...$metadata, ...$tiles['layers']];
            $estimatedBytes += $tiles['bytes'];
            if (count($metadata) + count($tiles['shadows']['layers'] ?? []) > self::MAX_LAYERS
                || $estimatedBytes > self::MAX_SOURCE_BYTES) {
                throw new InvalidArgumentException('Retained world exceeds the bounded layer or source-memory budget with its tiles.');
            }
        }
        // Each distinct cell is held once, and each row as indices to it.
        $wireCells = $indices = [];
        $rowCells = PackedGrid::fromRows((static function () use ($grid, $styled, $readOwnerIds, &$wireCells, &$indices): \Generator {
            foreach ($grid as $y => $cells) {
                $ownerIds = $readOwnerIds($y);
                $row = [];
                foreach ($cells as $x => $cell) {
                    $ownerId = $ownerIds[$x];
                    if (!isset($indices[$ownerId][$cell])) {
                        $indices[$ownerId][$cell] = count($wireCells);
                        $wireCells[] = [...$styled[$cell], 'ownerLayerId' => $ownerId];
                    }
                    $row[] = $indices[$ownerId][$cell];
                }
                yield $row;
            }
        })());
        return new self($id, ['columns' => $width, 'rows' => $height, 'cellWidth' => FieldViewport::TILE_SIZE,
            'cellHeight' => FieldViewport::TILE_SIZE, 'layers' => $metadata,
            ...($tiles === null ? [] : ['tileset' => $tiles['tileset']])], $wireCells, $rowCells, $tiles['rows'] ?? [],
            array_column(array_filter($metadata, static fn(array $layer): bool => $layer['kind'] !== 'tiles'), 'id'),
            $estimatedBytes, $tiles['animated'] ?? false, $tiles['covers'] ?? [], $tiles['shadows'] ?? null);
    }

    /**
     * The tileset catalog, tile layers and their rows, or null when no sheet
     * is usable (the map then shows its terminal glyphs). Tiles whose sheet is
     * unusable are left out, and their cells show glyphs. Both draw bands of
     * a tile layer cover the glyphs of the gameplay layer it belongs to.
     *
     * A corner-edged kind's cells are left out of their tile layer's rows and
     * drawn instead, with the cells beside them, in a layer of their own per
     * kind (`tiles:<layer>:corners:<kind>`, after `:above` in the above band)
     * that paints right after its tile layer's band, kinds in the tileset's
     * kind order; kinds in one join group share one edge where they meet.
     * Its corner sheet follows the tileset's sheets, and only when the map
     * shows the kind, so a map without corner-edged kinds is uploaded
     * exactly as before.
     *
     * Tile layers are shaped one at a time, so no more than one layer's
     * cells are ever held as arrays.
     *
     * @return array{tileset: array<string, mixed>, layers: list<array<string, mixed>>, rows: array<string, array<int, string>>, bytes: int, animated: bool, covers: array<string, string>, shadows: array{tiles: list<array<string, mixed>>, layers: list<array<string, mixed>>, rows: array<string, array<int, string>>}|null}|null
     */
    private static function getTilePresentation(MapGraphics $graphics, MapLayerSet $mapLayers, string $assetRoot,
        array $coveredCells = [], array $tileCells = []): ?array
    {
        $gameplayIds = [];
        foreach ($mapLayers->layers as $mapLayer) {
            if (!$mapLayer->decoration) { $gameplayIds[$mapLayer->name] = PresentationLayerPolicy::getMapLayerId($mapLayer); }
        }
        $usable = $graphics->tileset->getUsableSheets($assetRoot);
        if ($usable === null) {
            return null;
        }
        $wireSheets = array_values($usable['sheets']);
        $sheetIndices = array_flip(array_keys($usable['sheets']));
        $size = $usable['tileSize'];
        $catalog = $tiles = [];
        $bytes = array_sum(array_map(strlen(...), $wireSheets));
        $animated = false;
        $layers = $rows = $covers = [];
        // One shown row: remove owned source art, then present currently available object tiles.
        $readShown = static fn(MapTileLayer $layer): \Generator => $layer->yieldShownRows(
            $coveredCells[$layer->name] ?? [], $tileCells[$layer->name] ?? []);
        $cornerTiles = $graphics->tileset->getCornerTiles();
        $cornerJoins = $graphics->tileset->getCornerJoins();
        $cornerLayers = [];
        $cornerSheets = $cornerTiles === [] ? [] : $graphics->tileset->getUsableCornerSheets($assetRoot, $size,
            self::getShownCornerTiles($graphics->layers, $readShown, $cornerTiles, $cornerLayers));
        // Tiles share many pieces (an autotile's quarters recur across its shapes); equal pieces share one array.
        $shared = [];
        $addTile = static function (string $key, int $shiftX, int $shiftY, callable $compose)
            use (&$catalog, &$tiles, &$bytes, &$animated, &$sheetIndices, &$shared): int {
            if (isset($catalog[$key])) {
                return $catalog[$key];
            }
            if (count($catalog) >= self::MAX_CATALOG_TILES) {
                throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_CATALOG_TILES . '-tile catalog.');
            }
            $frames = $compose();
            $animated = $animated || count($frames) > 1;
            $catalog[$key] = count($tiles);
            $tile = [
                ...($shiftX === 0 ? [] : ['left' => $shiftX]),
                ...($shiftY === 0 ? [] : ['top' => $shiftY]),
                'frames' => array_map(static fn(array $pieces): array => array_map(
                    static function (TilePiece $piece) use ($sheetIndices, &$bytes): array {
                        $bytes += self::PIECE_SOURCE_BYTES;
                        return ['sheet' => $sheetIndices[$piece->sheet], 'x' => $piece->x, 'y' => $piece->y,
                            'width' => $piece->width, 'height' => $piece->height, 'left' => $piece->left, 'top' => $piece->top];
                    }, $pieces), $frames),
            ];
            foreach ($tile['frames'] as $frame => $framePieces) {
                foreach ($framePieces as $index => $piece) {
                    $tile['frames'][$frame][$index] = $shared[implode(':', $piece)] ??= $piece;
                }
            }
            $tiles[] = $tile;
            return $catalog[$key];
        };
        $shadowStyle = $graphics->tileset->shadows;
        $casters = $ground = [];
        foreach ($graphics->layers as $index => $layer) {
            $covered = $gameplayIds[$graphics->owners[$layer->name] ?? ''] ?? null;
            $bands = $cornerBands = [];
            // A layer offset in field cells, each one tile.
            [$offsetX, $offsetY] = $graphics->offsets[$layer->name] ?? [0.0, 0.0];
            $shiftX = (int)round($offsetX * $size);
            $shiftY = (int)round($offsetY * $size);
            // Each tile identity the layer shows is placed once: its catalog tile and band, or false when its cell
            // shows nothing here (empty, drawn by corner tiles, or on an unusable sheet).
            $placements = [];
            foreach (AutotileShape::yieldRows($readShown($layer)) as $y => $cells) {
                if ($shadowStyle !== null) {
                    self::collectShadowRow($graphics, $index, $y, $cells, $sheetIndices, $casters, $ground);
                }
                $rowBands = ['below' => '', 'above' => ''];
                foreach ($cells as $x => $tileId) {
                    $placement = $placements[$tileId] ??= $tileId === TileId::EMPTY || CornerShape::isCornerTile($tileId, $cornerTiles)
                        || !isset($sheetIndices[TileId::getSheet($tileId)?->value ?? '']) ? false
                        : [$addTile("{$tileId}:{$shiftX}:{$shiftY}", $shiftX, $shiftY,
                            static fn(): array => TileComposer::compose($tileId, $size, $graphics->tileset->isTable($tileId))),
                            $graphics->tileset->isAbove($tileId) ? 'above' : 'below'];
                    if ($placement !== false) {
                        $rowBands[$placement[1]] .= pack('vv', $x, $placement[0]);
                        $bytes += self::TILE_CELL_SOURCE_BYTES;
                    }
                }
                foreach ($rowBands as $band => $pairs) {
                    if ($pairs !== '') { $bands[$band][$y] = $pairs; }
                }
            }
            // Corner cases need the whole layer, so only a layer showing a corner-edged kind is read whole.
            $shown = $cornerSheets === [] || !isset($cornerLayers[$layer->name]) ? []
                : iterator_to_array($readShown($layer));
            foreach ($shown === [] ? [] : CornerShape::yieldCorners($shown, array_keys($cornerSheets), $cornerJoins)
                as [$x, $y, $flag, $cases]) {
                $sheet = $cornerSheets[$flag];
                if (!isset($sheetIndices[$sheet->key])) {
                    $sheetIndices[$sheet->key] = count($wireSheets);
                    $wireSheets[] = $sheet->asset;
                    $bytes += strlen($sheet->asset);
                }
                $tile = $addTile("corners:{$flag}:{$cases}:{$shiftX}:{$shiftY}", $shiftX, $shiftY,
                    static fn(): array => TileComposer::composeCorners($sheet, $cases, $size));
                $band = $graphics->tileset->isAbove($flag) ? 'above' : 'below';
                $cornerBands[$band][$sheet->kind][$y] = ($cornerBands[$band][$sheet->kind][$y] ?? '') . pack('vv', $x, $tile);
                $bytes += self::TILE_CELL_SOURCE_BYTES;
            }
            unset($shown);
            foreach (['below' => false, 'above' => true] as $band => $above) {
                $bandId = PresentationLayerPolicy::getTileLayerId($layer->name, $above);
                $drawn = isset($bands[$band]) ? [$bandId => $bands[$band]] : [];
                foreach ($cornerSheets as $sheet) {
                    if (isset($cornerBands[$band][$sheet->kind])) {
                        $drawn[$bandId . ':corners:' . $sheet->kind] = $cornerBands[$band][$sheet->kind];
                    }
                }
                foreach ($drawn as $layerId => $bandRows) {
                    $layers[] = ['id' => $layerId, 'layer' => PresentationLayerPolicy::getTileLayerOrder($layer->order, $above),
                        'kind' => 'tiles'];
                    if ($covered !== null) { $covers[$layerId] = $covered; }
                    $rows[$layerId] = $bandRows;
                }
            }
        }
        if ($tiles === []) {
            return null;
        }
        if (count($wireSheets) > self::MAX_SHEETS) {
            throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_SHEETS . '-sheet tileset budget with its corner sheets.');
        }
        $shadows = $shadowStyle === null ? null : self::getTileShadows($graphics, $casters, $ground, $size, count($tiles), $bytes);
        if (count($tiles) + count($shadows['tiles'] ?? []) > self::MAX_CATALOG_TILES) {
            throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_CATALOG_TILES . '-tile catalog.');
        }
        return ['tileset' => ['tileSize' => $usable['tileSize'], 'sheets' => $wireSheets, 'tiles' => $tiles],
            'layers' => $layers, 'rows' => $rows, 'bytes' => $bytes, 'animated' => $animated, 'covers' => $covers,
            'shadows' => $shadows];
    }

    /**
     * The corner-edged kinds' autotiles the map shows, so only their sheets are read and sent.
     *
     * @param list<MapTileLayer> $layers
     * @param callable(MapTileLayer): Generator $readShown Source coverage and current object tiles applied before shaping.
     * @param list<int> $cornerTiles
     * @param array<string, true> $shownOn Set to the names of the layers that show any.
     * @return list<int>
     */
    private static function getShownCornerTiles(array $layers, callable $readShown, array $cornerTiles, array &$shownOn): array
    {
        $found = $flags = [];
        foreach ($layers as $layer) {
            foreach ($readShown($layer) as $y => $row) {
                foreach ($row as $x => $tileId) {
                    // Each tile identity's corner-edged kind, or false for none, is read once.
                    $flag = $flags[$tileId] ??= CornerShape::isCornerTile($tileId, $cornerTiles) ? TileId::getFlagId($tileId) : false;
                    if ($flag !== false) {
                        $found[$flag] = true;
                        $shownOn[$layer->name] = true;
                    }
                }
            }
        }
        return array_keys($found);
    }

    /**
     * Records which cells of one shaped tile layer row cast a shadow and which
     * show a tile below the characters, for {@see getTileShadows()}: each row
     * as a string holding, per cell, the casting layer's position plus one
     * (casters) or "\1" (ground). Later layers are higher, so a caster of a
     * later layer takes the cell.
     *
     * @param list<int> $cells The row's cells, shaped.
     * @param array<string, int> $sheetIndices The usable sheets.
     * @param array<int, string> $casters
     * @param array<int, string> $ground
     */
    private static function collectShadowRow(MapGraphics $graphics, int $index, int $y, array $cells, array $sheetIndices,
        array &$casters, array &$ground): void
    {
        $style = $graphics->tileset->shadows;
        $layer = $graphics->layers[$index];
        foreach ($cells as $x => $tileId) {
            if ($tileId === TileId::EMPTY || !isset($sheetIndices[TileId::getSheet($tileId)?->value ?? ''])) {
                continue;
            }
            if ($style->isCaster($tileId)) {
                $casters[$y] ??= str_repeat("\0", count($cells));
                $caster = ord($casters[$y][$x]);
                if ($caster === 0 || $graphics->layers[$caster - 1]->order <= $layer->order) {
                    $casters[$y][$x] = chr($index + 1);
                }
            }
            if (!$graphics->tileset->isAbove($tileId)) {
                $ground[$y] ??= str_repeat("\0", count($cells));
                $ground[$y][$x] = "\1";
            }
        }
    }

    /**
     * The bands the tileset's casters throw on the cells to their right, as
     * RPG Maker's auto-shadow does, read from the tiles every upload: a cell
     * is shaded when a caster in any tile layer stands to its left, it shows
     * a tile below the characters itself, and it holds no caster. So painting
     * or erasing either neighbour needs nothing else to keep them true. Each
     * band belongs to the highest layer whose caster throws it and keeps that
     * layer's offset.
     *
     * @param array<int, string> $casters Each row's casting layers ({@see collectShadowRow()}).
     * @param array<int, string> $ground Each row's cells showing a tile below the characters.
     * @param int $tileCount Catalog tiles before the bands' fill tiles.
     * @return array{tiles: list<array<string, mixed>>, layers: list<array<string, mixed>>, rows: array<string, array<int, string>>}|null
     */
    private static function getTileShadows(MapGraphics $graphics, array $casters, array $ground, int $size,
        int $tileCount, int &$bytes): ?array
    {
        $style = $graphics->tileset->shadows;
        $cast = [];
        foreach ($casters as $y => $cells) {
            for ($x = 0, $width = strlen($cells); $x < $width; $x++) {
                $index = ord($cells[$x]) - 1;
                if ($index >= 0 && ($ground[$y][$x + 1] ?? "\0") === "\1" && ($cells[$x + 1] ?? "\0") === "\0") {
                    $cast[$index][$y][] = $x + 1;
                }
            }
        }
        if ($cast === []) {
            return null;
        }
        $width = max(1, (int)round($style->width * $size));
        $fill = [0, 0, 0, max(1, (int)round($style->opacity * 255))];
        $tiles = $layers = $rows = $catalog = [];
        ksort($cast);
        foreach ($cast as $index => $castRows) {
            $layer = $graphics->layers[$index];
            [$offsetX, $offsetY] = $graphics->offsets[$layer->name] ?? [0.0, 0.0];
            $shiftX = (int)round($offsetX * $size);
            $shiftY = (int)round($offsetY * $size);
            $key = "{$shiftX}:{$shiftY}";
            if (!isset($catalog[$key])) {
                $catalog[$key] = $tileCount + count($tiles);
                $tiles[] = ['width' => $width, ...($shiftX === 0 ? [] : ['left' => $shiftX]), ...($shiftY === 0 ? [] : ['top' => $shiftY]),
                    'frames' => [[['fill' => $fill, 'width' => $width, 'height' => $size, 'left' => 0, 'top' => 0]]]];
                $bytes += self::PIECE_SOURCE_BYTES;
            }
            $layerId = 'tiles:' . $layer->name . ':shadows';
            $layers[] = ['id' => $layerId, 'layer' => PresentationLayerPolicy::getTileLayerOrder($layer->order, false), 'kind' => 'shadows'];
            $bytes += self::LAYER_SOURCE_BYTES;
            ksort($castRows);
            foreach ($castRows as $y => $columns) {
                sort($columns);
                $rows[$layerId][$y] = implode('', array_map(static fn(int $column): string => pack('vv', $column, $catalog[$key]), $columns));
                $bytes += count($columns) * self::TILE_CELL_SOURCE_BYTES;
            }
        }
        return ['tiles' => $tiles, 'layers' => $layers, 'rows' => $rows];
    }

    /**
     * The cells this world shows by their terminal glyph: a visible glyph that
     * no tile covers, by the rule a renderer that negotiated tile covers
     * applies ({@see getOperations()}): a tile hides the glyph of the gameplay
     * layer its tile layer belongs to, and a tile of a layer that belongs to
     * none hides any glyph. Every such cell is one the graphical field draws
     * without art. Rows are read one at a time, so a world map is never held
     * as per-cell arrays.
     *
     * @return list<array{x: int, y: int, glyph: string, layerId: string}> In row order.
     */
    public function getGlyphCells(): array
    {
        $visible = [];
        foreach ($this->wireCells as $index => $cell) {
            if (trim($cell['glyph']) !== '') { $visible[$index] = true; }
        }
        if ($visible === []) { return []; }
        $cells = [];
        for ($y = 0; $y < $this->rowCells->height; $y++) {
            $covered = null;
            foreach ($this->rowCells->getRowValues($y) as $x => $index) {
                if (!isset($visible[$index])) { continue; }
                $covered ??= $this->readCoveredRow($y);
                $owner = $this->wireCells[$index]['ownerLayerId'];
                if (isset($covered[$x][$owner]) || isset($covered[$x][''])) { continue; }
                $cells[] = ['x' => $x, 'y' => $y, 'glyph' => $this->wireCells[$index]['glyph'], 'layerId' => $owner];
            }
        }
        return $cells;
    }

    /**
     * The gameplay layers each column's tiles cover in one row, `''` for a tile
     * of a layer that belongs to none.
     *
     * @return array<int, array<string, true>>
     */
    private function readCoveredRow(int $y): array
    {
        $covered = [];
        foreach ($this->tileRows as $layerId => $rows) {
            if (!isset($rows[$y])) { continue; }
            $owner = $this->tileCovers[$layerId] ?? '';
            $values = unpack('v*', $rows[$y]);
            for ($index = 1, $count = count($values); $index < $count; $index += 2) {
                $covered[$values[$index]][$owner] = true;
            }
        }
        return $covered;
    }

    /**
     * The cell's text as the renderer draws it, with the colours of its first
     * visible character (the background of its first character).
     *
     * @return array{glyph: string, foreground: ?array, background: ?array}
     */
    private static function getWireCell(string $cell): array
    {
        $symbols = TerminalText::visibleSymbols($cell);
        $glyph = implode('', array_map(TerminalText::rendererScalar(...), $symbols));
        $visible = array_values(array_filter($symbols, static fn(string $symbol): bool => trim(TerminalText::stripAnsi($symbol)) !== ''));
        $foreground = SgrColorParser::parse($visible[0] ?? $symbols[0] ?? ' ')['foreground'];
        $background = SgrColorParser::parse($symbols[0] ?? ' ')['background'];
        return ['glyph' => $glyph === '' ? ' ' : $glyph,
            'foreground' => $foreground?->toArray(), 'background' => $background?->toArray()];
    }
}
