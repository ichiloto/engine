<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use InvalidArgumentException;

/** The ordered authored layers, shared by runtime and authoring tools. */
final readonly class MapLayerSet
{
    /** @var list<MapLayer> */
    public array $layers;
    /** @var non-empty-list<MapLayer> */
    private array $gameplayLayers;
    /** @var list<list<int>> Topmost gameplay owner, indexed into the sorted layers. */
    public array $gameplayOwners;
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
            $width = count($layers[0]->grid[0] ?? []);
            if ($width === 0) {
                throw new InvalidArgumentException("Layer {$layers[0]->path} must not be empty.");
            }
            foreach ($layers as $layer) {
                $this->assertMatchingGrid($layer->grid, "Layer {$layer->path}");
            }
        }
        $result = $owners = [];
        foreach ($this->layers as $index => $layer) {
            if ($layer->decoration) { continue; }
            if ($result === []) {
                $result = $layer->grid;
                foreach ($result as $y => $row) { $owners[$y] = array_fill(0, count($row), $index); }
                continue;
            }
            foreach ($layer->grid as $y => $row) {
                foreach ($row as $x => $cell) {
                    if (!MapCell::isBlank($layer->glyphs[$y][$x])) {
                        $result[$y][$x] = MapCell::overlay($result[$y][$x], $cell);
                        $owners[$y][$x] = $index;
                    }
                }
            }
        }
        $this->composedGrid = $result;
        $this->gameplayOwners = $owners;
    }

    /** @return list<list<string>> */
    public function getComposedGrid(): array
    {
        return $this->composedGrid;
    }

    public function getGameplayLayerAt(int $x, int $y): MapLayer
    {
        $index = $this->gameplayOwners[$y][$x] ?? null;
        return $index === null ? $this->gameplayLayers[0] : $this->layers[$index];
    }

    /** @param array<int, string[]> $grid */
    public function assertMatchingGrid(array $grid, string $context): void
    {
        $base = $this->layers[0]->grid;
        if (count($grid) !== count($base)) {
            throw new InvalidArgumentException($context . ' must have ' . count($base) . ' rows.');
        }
        foreach ($base as $rowIndex => $row) {
            if (count($grid[$rowIndex] ?? []) !== count($row)) {
                throw new InvalidArgumentException($context . " row {$rowIndex} must be " . count($row) . ' cells wide.');
            }
        }
    }
}
