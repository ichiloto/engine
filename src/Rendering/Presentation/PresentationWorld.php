<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilePiece;
use InvalidArgumentException;

/**
 * Immutable, map-owned upload. Camera movement never visits its cells in PHP.
 * One wire cell is one terminal cell, drawn in the field as a
 * FieldViewport::CELL_WIDTH x CELL_HEIGHT box. A map with graphics also
 * carries its tileset: the sheets, and a catalog of the tile identities it
 * uses composed into generic pieces, so the renderer needs no RPG Maker
 * knowledge. A tile is placed at a cell and covers FieldViewport::TILE_COLUMNS
 * cells across. Tile layers draw at -100 + NN, their `above` tiles at 900 + NN.
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
    public const int BELOW_TILES = -100;
    public const int ABOVE_TILES = 900;

    /** @param list<array<string, mixed>> $operations */
    private function __construct(public string $id, public array $operations, public array $textLayerIds,
        public int $estimatedSourceBytes, public bool $animated = false) {}

    public static function getFromLayers(MapLayerSet $layers, string $id = 'map', ?MapGraphics $graphics = null,
        string $assetRoot = ''): self
    {
        $grid = $layers->getComposedGrid();
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
                    + strlen(PresentationLayerPolicy::getMapLayerId($layers->getGameplayLayerAt($x, $y)));
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
        $tiles = $graphics === null ? null : self::getTilePresentation($graphics, $assetRoot, $id);
        if ($tiles !== null) {
            $metadata = [...$metadata, ...$tiles['layers']];
            $estimatedBytes += $tiles['bytes'];
            if (count($metadata) > self::MAX_LAYERS || $estimatedBytes > self::MAX_SOURCE_BYTES) {
                throw new InvalidArgumentException('Retained world exceeds the bounded layer or source-memory budget with its tiles.');
            }
        }
        foreach ($grid as $y => $cells) {
            $wire = [];
            foreach ($cells as $x => $cell) {
                $wire[] = [...$styled[$cell], 'ownerLayerId' => PresentationLayerPolicy::getMapLayerId($layers->getGameplayLayerAt($x, $y))];
            }
            $rows[] = ['op' => 'worldRows', 'id' => $id, 'rows' => [['row' => $y, 'cells' => $wire]]];
        }
        return new self($id, [['op' => 'put', 'kind' => 'world', 'id' => $id,
            'value' => ['columns' => $width, 'rows' => $height, 'cellWidth' => FieldViewport::CELL_WIDTH,
                'cellHeight' => FieldViewport::CELL_HEIGHT, 'layers' => $metadata,
                ...($tiles === null ? [] : ['tileset' => $tiles['tileset']])]], ...$rows, ...($tiles['operations'] ?? [])],
            array_column(array_filter($metadata, static fn(array $layer): bool => $layer['kind'] !== 'tiles'), 'id'),
            $estimatedBytes, $tiles['animated'] ?? false);
    }

    /**
     * The tileset catalog, tile layers and their rows, or null when no sheet
     * is usable (the map then shows its terminal glyphs). Tiles whose sheet is
     * unusable are left out, and their cells show glyphs.
     *
     * @return array{tileset: array<string, mixed>, layers: list<array<string, mixed>>, operations: list<array<string, mixed>>, bytes: int, animated: bool}|null
     */
    private static function getTilePresentation(MapGraphics $graphics, string $assetRoot, string $id): ?array
    {
        $usable = $graphics->tileset->getUsableSheets($assetRoot);
        if ($usable === null) {
            return null;
        }
        $sheetNames = array_keys($usable['sheets']);
        $sheetIndices = array_flip($sheetNames);
        $catalog = $tiles = [];
        $bytes = array_sum(array_map(strlen(...), $usable['sheets']));
        $animated = false;
        foreach ($graphics->layers as $layer) {
            foreach ($layer->getUsedIds() as $tileId) {
                if (isset($catalog[$tileId]) || !isset($sheetIndices[TileId::getSheet($tileId)?->value ?? ''])) {
                    continue;
                }
                if (count($catalog) >= self::MAX_CATALOG_TILES) {
                    throw new InvalidArgumentException('Retained world exceeds the ' . self::MAX_CATALOG_TILES . '-tile catalog.');
                }
                $frames = TileComposer::compose($tileId, $usable['tileSize'], $graphics->tileset->isTable($tileId));
                $animated = $animated || count($frames) > 1;
                $catalog[$tileId] = count($tiles);
                $tiles[] = ['frames' => array_map(static fn(array $pieces): array => array_map(
                    static function (TilePiece $piece) use ($sheetIndices, &$bytes): array {
                        $bytes += self::PIECE_SOURCE_BYTES;
                        return ['sheet' => $sheetIndices[$piece->sheet->value], 'x' => $piece->x, 'y' => $piece->y,
                            'width' => $piece->width, 'height' => $piece->height, 'left' => $piece->left, 'top' => $piece->top];
                    }, $pieces), $frames)];
            }
        }
        $layers = $operations = [];
        foreach ($graphics->layers as $layer) {
            $bands = [];
            foreach ($layer->tiles as $y => $row) {
                foreach ($row as $x => $tileId) {
                    if (!isset($catalog[$tileId])) { continue; }
                    $band = $graphics->tileset->isAbove($tileId) ? 'above' : 'below';
                    $bands[$band][$y][] = ['column' => $x, 'tile' => $catalog[$tileId]];
                    $bytes += self::TILE_CELL_SOURCE_BYTES;
                }
            }
            foreach (['below' => self::BELOW_TILES, 'above' => self::ABOVE_TILES] as $band => $base) {
                if (!isset($bands[$band])) { continue; }
                $layerId = 'tiles:' . $layer->name . ($band === 'above' ? ':above' : '');
                $layers[] = ['id' => $layerId, 'layer' => $base + $layer->order, 'kind' => 'tiles'];
                foreach ($bands[$band] as $y => $cells) {
                    $operations[] = ['op' => 'worldTiles', 'id' => $id, 'layerId' => $layerId,
                        'rows' => [['row' => $y, 'cells' => $cells]]];
                }
            }
        }
        if ($tiles === []) {
            return null;
        }
        return ['tileset' => ['tileSize' => $usable['tileSize'], 'sheets' => array_values($usable['sheets']), 'tiles' => $tiles],
            'layers' => $layers, 'operations' => $operations, 'bytes' => $bytes, 'animated' => $animated];
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
