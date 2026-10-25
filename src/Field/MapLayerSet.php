<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\PackedGrid;
use InvalidArgumentException;

/** The ordered authored layers, shared by runtime and authoring tools. */
final readonly class MapLayerSet
{
    /** @var list<MapLayer> */
    public array $layers;
    /** @var non-empty-list<MapLayer> */
    private array $gameplayLayers;
    /** Topmost gameplay owner of each cell, indexed into the sorted layers. */
    private PackedGrid $owners;
    /** @var list<list<string>> */
    private array $composedGrid;

    /** @param list<MapLayer> $layers */
    public function __construct(array $layers, public bool $legacy = false)
    {
        // Detach caller-owned array references so the retained indexes cannot go stale.
        $layers = array_map(static fn(MapLayer $layer): MapLayer => $layer, $layers);
        if ($layers === [] || !array_filter($layers, static fn(MapLayer $layer): bool => !$layer->decoration)) {
            throw new InvalidArgumentException('A map requires at least one gameplay layer.');
        }
        usort($layers, static fn(MapLayer $a, MapLayer $b): int => $a->order <=> $b->order);
        $names = $orders = [];
        foreach ($layers as $layer) {
            if (isset($names[$layer->name]) || isset($orders[$layer->order])) {
                throw new InvalidArgumentException("Layer {$layer->path} has a duplicate name or order prefix.");
            }
            $names[$layer->name] = $orders[$layer->order] = true;
        }
        $this->layers = array_values($layers);
        $this->gameplayLayers = array_values(array_filter($layers, static fn(MapLayer $layer): bool => !$layer->decoration));
        if (!$legacy) {
            if ($layers[0]->getRowLength(0) === 0) {
                throw new InvalidArgumentException("Layer {$layers[0]->path} must not be empty.");
            }
            foreach ($layers as $layer) {
                $this->assertMatchingLayer($layer);
            }
        }
        $gameplay = [];
        foreach ($this->layers as $index => $layer) {
            if (!$layer->decoration) { $gameplay[$index] = $layer; }
        }
        $baseIndex = array_key_first($gameplay);
        $base = $gameplay[$baseIndex];
        $grid = $owners = [];
        // Row by row, so no layer's whole grid is ever built to compose them.
        for ($y = 0; $y < $base->height; $y++) {
            $row = $base->getRowCells($y);
            $owned = array_fill(0, count($row), $baseIndex);
            foreach ($gameplay as $index => $layer) {
                if ($index === $baseIndex) { continue; }
                foreach ($layer->getRowGlyphs($y) as $x => $glyph) {
                    if ($glyph !== ' ') {
                        $row[$x] = $layer->getCellAt($x, $y);
                        $owned[$x] = $index;
                    }
                }
            }
            $grid[] = $row;
            $owners[] = $owned;
        }
        $this->composedGrid = $grid;
        $this->owners = PackedGrid::fromRows($owners);
    }

    /** @return list<list<string>> */
    public function getComposedGrid(): array
    {
        return $this->composedGrid;
    }


    /** @return list<list<int>> Each cell's topmost gameplay owner, indexed into the sorted layers, built for the caller. */
    public function readGameplayOwners(): array
    {
        return $this->owners->readRows();
    }

    public function getGameplayLayerAt(int $x, int $y): MapLayer
    {
        $index = $this->owners->getValueAt($x, $y);
        return $index === null ? $this->gameplayLayers[0] : $this->layers[$index];
    }

    /** @param array<int, string[]> $grid */
    public function assertMatchingGrid(array $grid, string $context): void
    {
        $this->assertMatchingRows(count($grid), static fn(int $y): int => count($grid[$y] ?? []), $context);
    }

    private function assertMatchingLayer(MapLayer $layer): void
    {
        $this->assertMatchingRows($layer->height, $layer->getRowLength(...), "Layer {$layer->path}");
    }

    /** @param \Closure(int): int $rowLength */
    private function assertMatchingRows(int $height, \Closure $rowLength, string $context): void
    {
        $base = $this->layers[0];
        if ($height !== $base->height) {
            throw new InvalidArgumentException($context . ' must have ' . $base->height . ' rows.');
        }
        for ($y = 0; $y < $base->height; $y++) {
            if ($rowLength($y) !== $base->getRowLength($y)) {
                throw new InvalidArgumentException($context . " row {$y} must be " . $base->getRowLength($y) . ' tiles wide.');
            }
        }
    }
}
