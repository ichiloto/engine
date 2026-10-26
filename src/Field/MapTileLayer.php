<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Generator;
use Ichiloto\Engine\Core\PackedGrid;
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
    /** Number of rows. */
    public int $height;
    /** The longest row's cell count. */
    public int $width;
    /** Each cell's tile identity, packed ({@see PackedGrid}), so a world map costs bytes per cell. */
    private PackedGrid $grid;

    public function __construct(
        public string $name,
        public int $order,
        public string $path,
        string $text,
    ) {
        $this->grid = PackedGrid::fromRows(self::parseRows($text, $path));
        $this->height = $this->grid->height;
        $this->width = $this->grid->width;
    }

    /** The tile identity at a cell, or null outside the layer. */
    public function getTileAt(int $x, int $y): ?int
    {
        return $this->grid->getValueAt($x, $y);
    }

    /** The number of cells in a row; 0 outside the layer. */
    public function getRowLength(int $y): int
    {
        return $this->grid->getRowLength($y);
    }

    /** @return list<int> A row's tile identities; empty outside the layer. */
    public function getRowTiles(int $y): array
    {
        return $this->grid->getRowValues($y);
    }

    /** @return list<list<int>> Every row's tile identities, built for the caller. */
    public function readTiles(): array
    {
        return $this->grid->readRows();
    }

    /** Rows must match the map's cells exactly, as terminal layers do. */
    public function assertMatches(MapLayerSet $layers): void
    {
        $grid = $layers->getComposedGrid();
        if ($this->height !== count($grid)) {
            throw new InvalidArgumentException("Tile layer {$this->path} must have " . count($grid) . ' rows.');
        }
        foreach ($grid as $y => $row) {
            if ($this->getRowLength($y) !== count($row)) {
                throw new InvalidArgumentException("Tile layer {$this->path} row {$y} must be " . count($row) . ' cells wide.');
            }
        }
    }

    /** @return list<list<string>> Each cell's entry as a tile layer file writes it: its tile identity. */
    public function getEntries(): array
    {
        return array_map(static fn(array $row): array => array_map(strval(...), $row), $this->readTiles());
    }

    /** @return list<int> Every tile identity the layer paints. */
    public function getUsedIds(): array
    {
        $used = [];
        for ($y = 0; $y < $this->height; $y++) {
            foreach ($this->getRowTiles($y) as $id) {
                if ($id !== TileId::EMPTY) { $used[$id] = true; }
            }
        }
        return array_keys($used);
    }

    /**
     * Each entry is checked once per distinct spelling: a layer repeats few
     * tiles over many cells.
     *
     * @return Generator<int, list<int>>
     */
    private static function parseRows(string $text, string $path): Generator
    {
        $tiles = [];
        foreach (preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: [] as $y => $line) {
            $row = [];
            foreach (preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x => $value) {
                $row[] = $tiles[$value] ??= self::readEntry($value)
                    ?? throw self::createEntryRefusal($path, $y, $x, $value);
            }
            yield $row;
        }
    }

    /** An entry's tile identity, or null when it names none. */
    private static function readEntry(string $value): ?int
    {
        if (!ctype_digit($value)) {
            return null;
        }
        $id = (int)$value;
        return $id === TileId::EMPTY || TileId::isValid($id) ? $id : null;
    }

    private static function createEntryRefusal(string $path, int $y, int $x, string $value): InvalidArgumentException
    {
        if (preg_match('/\A(?<id>\d+)[LR]\z/', $value, $match) === 1) {
            return new InvalidArgumentException("Tile layer {$path} row {$y}, cell {$x}: '{$value}' names half of tile {$match['id']}, but each field cell holds one whole tile; use whole tiles, such as '{$match['id']}'.");
        }
        return new InvalidArgumentException("Tile layer {$path} row {$y}, cell {$x}: '{$value}' is not an RPG Maker tile identity.");
    }
}
