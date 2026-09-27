<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;

/**
 * One graphical tile layer: an RPG Maker tile identity per terminal cell, 0
 * for none. A tile covers its cell and the next FieldViewport::TILE_COLUMNS - 1
 * cells across, so a floor is usually painted every other cell.
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

    /** @return list<int> Every tile identity the layer paints. */
    public function getUsedIds(): array
    {
        return array_values(array_unique(array_filter(array_merge(...$this->tiles ?: [[]]), static fn(int $id): bool => $id !== TileId::EMPTY)));
    }
}
