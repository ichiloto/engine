<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TileSlice;
use InvalidArgumentException;

/**
 * One graphical tile layer: an RPG Maker tile identity per terminal cell, 0
 * for none. A field cell is half a tile wide. An autotile is composed for its
 * own cell from its neighbours. Any other tile is drawn whole and centred on
 * its cell, or, with an `L` or `R` suffix (`42L`), only its left or right
 * half fills the cell, so a tile can also lie across two cells exactly.
 */
final readonly class MapTileLayer
{
    /** @var list<list<int>> */
    public array $tiles;
    /** @var array<int, array<int, TileSlice>> The halves named by suffixed entries, by row and cell. */
    public array $halves;

    public function __construct(
        public string $name,
        public int $order,
        public string $path,
        string $text,
    ) {
        $tiles = $halves = [];
        foreach (preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: [] as $y => $line) {
            $row = [];
            foreach (preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x => $value) {
                if (preg_match('/\A(?<id>\d+)(?<half>[LR])?\z/', $value, $match) !== 1
                    || ((int)$match['id'] !== TileId::EMPTY && !TileId::isValid((int)$match['id']))) {
                    throw new InvalidArgumentException("Tile layer {$path} row {$y}, cell {$x}: '{$value}' is not an RPG Maker tile identity.");
                }
                $id = (int)$match['id'];
                if (($match['half'] ?? '') !== '') {
                    if ($id === TileId::EMPTY || TileId::isAutotile($id)) {
                        throw new InvalidArgumentException("Tile layer {$path} row {$y}, cell {$x}: '{$value}' names a half, but only plain tiles have halves; autotiles are composed per cell.");
                    }
                    $halves[$y][$x] = $match['half'] === 'L' ? TileSlice::LEFT : TileSlice::RIGHT;
                }
                $row[] = $id;
            }
            $tiles[] = $row;
        }
        $this->tiles = $tiles;
        $this->halves = $halves;
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

    /** @return list<list<string>> Each cell's entry as authored: a tile identity with its half, if any. */
    public function getEntries(): array
    {
        return array_map(fn(array $row, int $y): array => array_map(
            fn(int $id, int $x): string => $id . match ($this->halves[$y][$x] ?? null) {
                TileSlice::LEFT => 'L',
                TileSlice::RIGHT => 'R',
                default => '',
            }, $row, array_keys($row)), $this->tiles, array_keys($this->tiles));
    }

    /** @return list<int> Every tile identity the layer paints. */
    public function getUsedIds(): array
    {
        return array_values(array_unique(array_filter(array_merge(...$this->tiles ?: [[]]), static fn(int $id): bool => $id !== TileId::EMPTY)));
    }
}
