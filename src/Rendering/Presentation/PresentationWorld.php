<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\CornerShape;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilePiece;
use InvalidArgumentException;

/**
 * Immutable, map-owned upload. Camera movement never visits its cells in PHP.
 * One wire cell is one terminal cell, drawn in the field as one
 * FieldViewport::TILE_SIZE square. A map with graphics also carries its
 * tileset: the sheets, and a catalog of the tile identities it uses composed
 * into generic pieces, so the renderer needs no RPG Maker knowledge. Each
 * cell shows one whole tile: an autotile composed for the cell from its
 * neighbours, or a plain tile. Tile layers draw at -100 + NN, their `above`
 * tiles at 900 + NN. A tile layer that belongs to a gameplay layer covers
 * only that layer's glyphs ({@see getOperations()}). A tileset with shadows
 * adds, for a renderer that negotiated them, the bands its raised tiles cast
 * on the cells to their right: derived from the tiles here on every upload,
 * never authored or stored.
 */
final readonly class PresentationWorld
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
    public const int BELOW_TILES = -100;
    public const int ABOVE_TILES = 900;

    /**
     * @param list<array<string, mixed>> $operations The upload every renderer accepts, without tile covers.
     * @param array<string, string> $tileCovers The gameplay layer id each covering tile layer id belongs to.
     * @param array{tiles: list<array<string, mixed>>, layers: list<array<string, mixed>>, operations: list<array<string, mixed>>}|null $tileShadows
     *   The shadow catalog tiles, layers and rows, sent only with tile shadows.
     */
    private function __construct(public string $id, public array $operations, public array $textLayerIds,
        public int $estimatedSourceBytes, public bool $animated = false, public array $tileCovers = [],
        public ?array $tileShadows = null) {}

    /**
     * The operations that upload this world. With tile covers, for a renderer
     * that negotiated tile_covers, each tile layer that belongs to a gameplay
     * layer names it as its `coversLayerId`: its tiles hide only that layer's
     * glyphs. Without, every tile hides the glyph of its cell, as before.
     *
     * With tile shadows, for a renderer that negotiated tile_shadows, the
     * shadow bands follow: their fill tiles after the catalog, so no other
     * tile's index moves, and one `shadows` layer per casting tile layer with
     * that layer's number. Layers of equal number paint in the order listed,
     * so each band paints over the tiles of its own and earlier layers and
     * under later layers and every character. A shadows layer never hides a
     * glyph. Without, nothing of the shadows is sent.
     *
     * @return list<array<string, mixed>>
     */
    public function getOperations(bool $tileCovers, bool $tileShadows = false): array
    {
        $operations = $this->operations;
        if ($tileCovers && $this->tileCovers !== []) {
            $operations[0]['value']['layers'] = array_map(fn(array $layer): array => isset($this->tileCovers[$layer['id']])
                ? [...$layer, 'coversLayerId' => $this->tileCovers[$layer['id']]] : $layer, $operations[0]['value']['layers']);
        }
        if ($tileShadows && $this->tileShadows !== null) {
            $operations[0]['value']['layers'] = [...$operations[0]['value']['layers'], ...$this->tileShadows['layers']];
            $operations[0]['value']['tileset']['tiles'] = [...$operations[0]['value']['tileset']['tiles'], ...$this->tileShadows['tiles']];
            $operations = [...$operations, ...$this->tileShadows['operations']];
        }
        return $operations;
    }

    public static function getFromLayers(MapLayerSet $layers, string $id = 'map', ?MapGraphics $graphics = null,
        string $assetRoot = '', array $coverage = [], array $terminalCells = []): self
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
                        if ($layer->glyphs[$y][$x] !== ' ') {
                            $cell = $layer->grid[$y][$x];
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
        $metadata = $rows = $styled = [];
        $estimatedBytes = count($layers->layers) * self::LAYER_SOURCE_BYTES;
        // Match the native retained-source charge before allocating per-cell wire arrays.
        foreach ($grid as $y => $cells) {
            foreach ($cells as $x => $cell) {
                $styled[$cell] ??= self::getWireCell($cell);
                $estimatedBytes += self::CELL_SOURCE_BYTES + strlen($styled[$cell]['glyph'])
                    + strlen(PresentationLayerPolicy::getMapLayerId($projectedOwners[$y][$x] ?? $layers->getGameplayLayerAt($x, $y)));
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
        $tiles = $graphics === null ? null : self::getTilePresentation($graphics, $layers, $assetRoot, $id, $coverage['tiles'] ?? []);
        if ($tiles !== null) {
            $metadata = [...$metadata, ...$tiles['layers']];
            $estimatedBytes += $tiles['bytes'];
            if (count($metadata) + count($tiles['shadows']['layers'] ?? []) > self::MAX_LAYERS
                || $estimatedBytes > self::MAX_SOURCE_BYTES) {
                throw new InvalidArgumentException('Retained world exceeds the bounded layer or source-memory budget with its tiles.');
            }
        }
        $wireCells = [];
        foreach ($grid as $y => $cells) {
            $wire = [];
            foreach ($cells as $x => $cell) {
                $ownerId = PresentationLayerPolicy::getMapLayerId($projectedOwners[$y][$x] ?? $layers->getGameplayLayerAt($x, $y));
                // Identical immutable cells share storage; caller edits remain isolated by PHP copy-on-write.
                $wireCells[$ownerId][$cell] ??= [...$styled[$cell], 'ownerLayerId' => $ownerId];
                $wire[] = $wireCells[$ownerId][$cell];
            }
            $rows[] = ['op' => 'worldRows', 'id' => $id, 'rows' => [['row' => $y, 'cells' => $wire]]];
        }
        return new self($id, [['op' => 'put', 'kind' => 'world', 'id' => $id,
            'value' => ['columns' => $width, 'rows' => $height, 'cellWidth' => FieldViewport::TILE_SIZE,
                'cellHeight' => FieldViewport::TILE_SIZE, 'layers' => $metadata,
                ...($tiles === null ? [] : ['tileset' => $tiles['tileset']])]], ...$rows, ...($tiles['operations'] ?? [])],
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
     * kind order. Its corner sheet follows the tileset's sheets, and only
     * when the map shows the kind, so a map without corner-edged kinds is
     * uploaded exactly as before.
     *
     * @return array{tileset: array<string, mixed>, layers: list<array<string, mixed>>, operations: list<array<string, mixed>>, bytes: int, animated: bool, covers: array<string, string>, shadows: array{tiles: list<array<string, mixed>>, layers: list<array<string, mixed>>, operations: list<array<string, mixed>>}|null}|null
     */
    private static function getTilePresentation(MapGraphics $graphics, MapLayerSet $mapLayers, string $assetRoot, string $id,
        array $coveredCells = []): ?array
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
        $layers = $operations = $covers = [];
        $shown = array_map(static function ($layer) use ($coveredCells): array {
            $tiles = $layer->tiles;
            foreach ($coveredCells[$layer->name] ?? [] as $y => $cells) {
                foreach ($cells as $x => $_) { $tiles[$y][$x] = TileId::EMPTY; }
            }
            return $tiles;
        }, $graphics->layers);
        $resolved = array_map(AutotileShape::resolveLayer(...), $shown);
        $cornerTiles = $graphics->tileset->getCornerTiles();
        $cornerSheets = $cornerTiles === [] ? [] : $graphics->tileset->getUsableCornerSheets($assetRoot, $size,
            self::getShownCornerTiles($shown, $cornerTiles));
        $addTile = static function (string $key, int $shiftX, int $shiftY, callable $compose)
            use (&$catalog, &$tiles, &$bytes, &$animated, &$sheetIndices): int {
            if (isset($catalog[$key])) {
                return $catalog[$key];
            }
            if (count($catalog) >= self::MAX_CATALOG_TILES) {
                throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_CATALOG_TILES . '-tile catalog.');
            }
            $frames = $compose();
            $animated = $animated || count($frames) > 1;
            $catalog[$key] = count($tiles);
            $tiles[] = [
                ...($shiftX === 0 ? [] : ['left' => $shiftX]),
                ...($shiftY === 0 ? [] : ['top' => $shiftY]),
                'frames' => array_map(static fn(array $pieces): array => array_map(
                    static function (TilePiece $piece) use ($sheetIndices, &$bytes): array {
                        $bytes += self::PIECE_SOURCE_BYTES;
                        return ['sheet' => $sheetIndices[$piece->sheet], 'x' => $piece->x, 'y' => $piece->y,
                            'width' => $piece->width, 'height' => $piece->height, 'left' => $piece->left, 'top' => $piece->top];
                    }, $pieces), $frames),
            ];
            return $catalog[$key];
        };
        foreach ($graphics->layers as $index => $layer) {
            $covered = $gameplayIds[$graphics->owners[$layer->name] ?? ''] ?? null;
            $bands = $cornerBands = [];
            // A layer offset in field cells, each one tile.
            [$offsetX, $offsetY] = $graphics->offsets[$layer->name] ?? [0.0, 0.0];
            $shiftX = (int)round($offsetX * $size);
            $shiftY = (int)round($offsetY * $size);
            foreach ($resolved[$index] as $y => $cells) {
                foreach ($cells as $x => $tileId) {
                    if ($tileId === TileId::EMPTY || CornerShape::isCornerTile($tileId, $cornerTiles)
                        || !isset($sheetIndices[TileId::getSheet($tileId)?->value ?? ''])) {
                        continue;
                    }
                    $tile = $addTile("{$tileId}:{$shiftX}:{$shiftY}", $shiftX, $shiftY,
                        static fn(): array => TileComposer::compose($tileId, $size, $graphics->tileset->isTable($tileId)));
                    $band = $graphics->tileset->isAbove($tileId) ? 'above' : 'below';
                    $bands[$band][$y][] = ['column' => $x, 'tile' => $tile];
                    $bytes += self::TILE_CELL_SOURCE_BYTES;
                }
            }
            foreach ($cornerSheets === [] ? [] : CornerShape::resolveLayer($shown[$index], array_keys($cornerSheets)) as $y => $cells) {
                foreach ($cells as $x => $kinds) {
                    foreach ($kinds as $flag => $cases) {
                        $sheet = $cornerSheets[$flag];
                        if (!isset($sheetIndices[$sheet->key])) {
                            $sheetIndices[$sheet->key] = count($wireSheets);
                            $wireSheets[] = $sheet->asset;
                            $bytes += strlen($sheet->asset);
                        }
                        $tile = $addTile("corners:{$flag}:{$cases}:{$shiftX}:{$shiftY}", $shiftX, $shiftY,
                            static fn(): array => TileComposer::composeCorners($sheet, $cases, $size));
                        $band = $graphics->tileset->isAbove($flag) ? 'above' : 'below';
                        $cornerBands[$band][$sheet->kind][$y][] = ['column' => $x, 'tile' => $tile];
                        $bytes += self::TILE_CELL_SOURCE_BYTES;
                    }
                }
            }
            foreach (['below' => self::BELOW_TILES, 'above' => self::ABOVE_TILES] as $band => $base) {
                $bandId = 'tiles:' . $layer->name . ($band === 'above' ? ':above' : '');
                $drawn = isset($bands[$band]) ? [$bandId => $bands[$band]] : [];
                foreach ($cornerSheets as $sheet) {
                    if (isset($cornerBands[$band][$sheet->kind])) {
                        $drawn[$bandId . ':corners:' . $sheet->kind] = $cornerBands[$band][$sheet->kind];
                    }
                }
                foreach ($drawn as $layerId => $rows) {
                    $layers[] = ['id' => $layerId, 'layer' => $base + $layer->order, 'kind' => 'tiles'];
                    if ($covered !== null) { $covers[$layerId] = $covered; }
                    foreach ($rows as $y => $cells) {
                        $operations[] = ['op' => 'worldTiles', 'id' => $id, 'layerId' => $layerId,
                            'rows' => [['row' => $y, 'cells' => $cells]]];
                    }
                }
            }
        }
        if ($tiles === []) {
            return null;
        }
        if (count($wireSheets) > self::MAX_SHEETS) {
            throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_SHEETS . '-sheet tileset budget with its corner sheets.');
        }
        $shadows = self::getTileShadows($graphics, $resolved, $sheetIndices, $size, count($tiles), $id, $bytes);
        if (count($tiles) + count($shadows['tiles'] ?? []) > self::MAX_CATALOG_TILES) {
            throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_CATALOG_TILES . '-tile catalog.');
        }
        return ['tileset' => ['tileSize' => $usable['tileSize'], 'sheets' => $wireSheets, 'tiles' => $tiles],
            'layers' => $layers, 'operations' => $operations, 'bytes' => $bytes, 'animated' => $animated, 'covers' => $covers,
            'shadows' => $shadows];
    }

    /**
     * The corner-edged kinds' autotiles the map shows, so only their sheets are read and sent.
     *
     * @param list<array<int, array<int, int>>> $shown Each tile layer's cells.
     * @param list<int> $cornerTiles
     * @return list<int>
     */
    private static function getShownCornerTiles(array $shown, array $cornerTiles): array
    {
        $found = [];
        foreach ($shown as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $tileId) {
                    if (CornerShape::isCornerTile($tileId, $cornerTiles)) {
                        $found[TileId::getFlagId($tileId)] = true;
                    }
                }
            }
        }
        return array_keys($found);
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
     * @param list<array<int, array<int, int>>> $resolved Each tile layer's cells, shaped.
     * @param array<string, int> $sheetIndices The usable sheets.
     * @param int $tileCount Catalog tiles before the bands' fill tiles.
     * @return array{tiles: list<array<string, mixed>>, layers: list<array<string, mixed>>, operations: list<array<string, mixed>>}|null
     */
    private static function getTileShadows(MapGraphics $graphics, array $resolved, array $sheetIndices, int $size,
        int $tileCount, string $id, int &$bytes): ?array
    {
        $style = $graphics->tileset->shadows;
        if ($style === null) {
            return null;
        }
        $casters = $ground = [];
        foreach ($graphics->layers as $index => $layer) {
            foreach ($resolved[$index] as $y => $cells) {
                foreach ($cells as $x => $tileId) {
                    if ($tileId === TileId::EMPTY || !isset($sheetIndices[TileId::getSheet($tileId)?->value ?? ''])) {
                        continue;
                    }
                    if ($style->isCaster($tileId)
                        && (!isset($casters[$y][$x]) || $graphics->layers[$casters[$y][$x]]->order <= $layer->order)) {
                        $casters[$y][$x] = $index;
                    }
                    if (!$graphics->tileset->isAbove($tileId)) {
                        $ground[$y][$x] = true;
                    }
                }
            }
        }
        $cast = [];
        foreach ($casters as $y => $cells) {
            foreach ($cells as $x => $index) {
                if (isset($ground[$y][$x + 1]) && !isset($casters[$y][$x + 1])) {
                    $cast[$index][$y][] = $x + 1;
                }
            }
        }
        if ($cast === []) {
            return null;
        }
        $width = max(1, (int)round($style->width * $size));
        $fill = [0, 0, 0, max(1, (int)round($style->opacity * 255))];
        $tiles = $layers = $operations = $catalog = [];
        ksort($cast);
        foreach ($cast as $index => $rows) {
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
            $layers[] = ['id' => $layerId, 'layer' => self::BELOW_TILES + $layer->order, 'kind' => 'shadows'];
            $bytes += self::LAYER_SOURCE_BYTES;
            ksort($rows);
            foreach ($rows as $y => $columns) {
                sort($columns);
                $operations[] = ['op' => 'worldTiles', 'id' => $id, 'layerId' => $layerId, 'rows' => [['row' => $y,
                    'cells' => array_map(static fn(int $column): array => ['column' => $column, 'tile' => $catalog[$key]], $columns)]]];
                $bytes += count($columns) * self::TILE_CELL_SOURCE_BYTES;
            }
        }
        return ['tiles' => $tiles, 'layers' => $layers, 'operations' => $operations];
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
