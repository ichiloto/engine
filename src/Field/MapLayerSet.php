<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** The ordered authored layers, shared by runtime and authoring tools. */
final readonly class MapLayerSet
{
    /** @var list<MapLayer> */
    public array $layers;
    /** @var non-empty-list<MapLayer> */
    private array $gameplayLayers;

    /** @param list<MapLayer> $layers */
    public function __construct(array $layers, public bool $legacy = false)
    {
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
    }

    /** @return list<list<string>> */
    public function getComposedGrid(): array
    {
        $result = [];
        foreach ($this->gameplayLayers as $layer) {
            if ($result === []) {
                $result = $layer->grid;
                continue;
            }
            foreach ($layer->grid as $y => $row) {
                foreach ($row as $x => $cell) {
                    if (TerminalText::stripAnsi($cell) !== ' ') {
                        $result[$y][$x] = $cell;
                    }
                }
            }
        }
        return $result;
    }

    public function getGameplayLayerAt(int $x, int $y): MapLayer
    {
        $gameplay = $this->gameplayLayers;
        for ($index = count($gameplay) - 1; $index > 0; $index--) {
            $cell = $gameplay[$index]->grid[$y][$x] ?? ' ';
            if (TerminalText::stripAnsi($cell) !== ' ') {
                return $gameplay[$index];
            }
        }
        return $gameplay[0];
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
                throw new InvalidArgumentException($context . " row {$rowIndex} must be " . count($row) . ' tiles wide.');
            }
        }
    }
}
