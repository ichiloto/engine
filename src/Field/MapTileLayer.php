<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;

/**
 * One graphical tile layer: an RPG Maker tile identity per terminal cell, 0
 * for none. A field cell is one whole tile. An autotile is composed for its
 * own cell from its neighbours; any other tile is drawn whole in its cell.
 * A cell holds exactly one whole tile, so an entry naming part of a tile
 * (`42L`) is refused rather than reinterpreted.
 */
final readonly class MapTileLayer
{
    /** @var list<list<int>> */
    public array $tiles;

    public function __construct(
        public string $name,
        public int $order,
        public string $path,
        string $text,
    ) {
        $tiles = [];
        foreach (preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: [] as $y => $line) {
            $row = [];
            foreach (preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x => $value) {
                if (preg_match('/\A(?<id>\d+)[LR]\z/', $value, $match) === 1) {
                    throw new InvalidArgumentException("Tile layer {$path} row {$y}, cell {$x}: '{$value}' names half of tile {$match['id']}, but each field cell holds one whole tile; use whole tiles, such as '{$match['id']}'.");
                }
                if (preg_match('/\A\d+\z/', $value) !== 1 || ((int)$value !== TileId::EMPTY && !TileId::isValid((int)$value))) {
                    throw new InvalidArgumentException("Tile layer {$path} row {$y}, cell {$x}: '{$value}' is not an RPG Maker tile identity.");
                }
                $row[] = (int)$value;
            }
            $tiles[] = $row;
        }
        $this->tiles = $tiles;
    }

    /** Rows must match the map's cells exactly, as terminal layers do. */
    public function assertMatches(MapLayerSet $layers): void
    {
        $grid = $layers->getComposedGrid();
        if (count($this->tiles) !== count($grid)) {
            throw new InvalidArgumentException("Tile layer {$this->path} must have " . count($grid) . ' rows.');
        }
        foreach ($grid as $y => $row) {
            if (count($this->tiles[$y]) !== count($row)) {
                throw new InvalidArgumentException("Tile layer {$this->path} row {$y} must be " . count($row) . ' cells wide.');
            }
        }
    }

    /** @return list<list<string>> Each cell's entry as a tile layer file writes it: its tile identity. */
    public function getEntries(): array
    {
        return array_map(static fn(array $row): array => array_map(strval(...), $row), $this->tiles);
    }

    /** @return list<int> Every tile identity the layer paints. */
    public function getUsedIds(): array
    {
        return array_values(array_unique(array_filter(array_merge(...$this->tiles ?: [[]]), static fn(int $id): bool => $id !== TileId::EMPTY)));
    }
}
