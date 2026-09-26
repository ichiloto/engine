<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;
use InvalidArgumentException;

/** Immutable, map-owned upload. Camera movement never visits its cells in PHP. */
final readonly class PresentationWorld
{
    public const int MAX_LAYERS = 64;
    public const int MAX_CELLS = 1048576;
    public const int MAX_EXTENT = 16384;
    public const int MAX_SOURCE_BYTES = 67108864;
    public const int LAYER_SOURCE_BYTES = 8192;
    public const int CELL_SOURCE_BYTES = 64;
    public const int TILE_SOURCE_BYTES = 16;

    /** @param list<array<string, mixed>> $operations */
    private function __construct(public string $id, public array $operations, public array $textLayerIds,
        public int $estimatedSourceBytes) {}

    /** @param array<string, GraphicalTileDefinition> $definitions */
    public static function getFromLayers(MapLayerSet $layers, array $definitions, string $id = 'map'): self
    {
        $grid = $layers->getComposedGrid();
        $height = count($grid);
        $width = max(array_map(count(...), $grid) ?: [0]);
        if ($height < 1 || $width < 1 || $height > self::MAX_EXTENT || $width > self::MAX_EXTENT
            || $width * $height > self::MAX_CELLS || count($layers->layers) > self::MAX_LAYERS) {
            throw new InvalidArgumentException('Retained world exceeds the bounded layer or cell budget.');
        }
        $metadata = $rows = $tileOperations = $styled = [];
        $estimatedBytes = count($layers->layers) * self::LAYER_SOURCE_BYTES;
        // Match the native retained-source charge before allocating per-cell wire arrays.
        foreach ($grid as $y => $symbols) {
            foreach ($symbols as $x => $symbol) {
                if (!isset($styled[$symbol])) {
                    $style = SgrColorParser::parse($symbol);
                    $styled[$symbol] = ['glyph' => TerminalText::rendererScalar($symbol),
                        'foreground' => $style['foreground']?->toArray(), 'background' => $style['background']?->toArray(),
                        'displayWidth' => NormalizedRow::symbolWidth($symbol)];
                }
                $estimatedBytes += self::CELL_SOURCE_BYTES + strlen($styled[$symbol]['glyph'])
                    + strlen(PresentationLayerPolicy::getMapLayerId($layers->getGameplayLayerAt($x, $y)));
            }
        }
        self::assertSourceBudget($estimatedBytes);
        $base = $layers->getGameplayLayerAt(-1, -1);
        $tileCount = 0;
        foreach ($layers->layers as $layer) {
            $layerId = PresentationLayerPolicy::getMapLayerId($layer);
            $definition = $definitions[$layer->name] ?? ($layers->legacy ? ($definitions['terrain'] ?? null) : null);
            $metadata[] = ['id' => $layerId, 'layer' => PresentationLayerPolicy::getMapLayerOrder($layer),
                'kind' => $layer->decoration ? 'decoration' : 'gameplay',
                ...($definition === null ? [] : ['asset' => $definition->asset,
                    'sources' => array_map(static fn($source) => $source->toArray(), $definition->sources)])];
            if ($definition === null) { continue; }
            $widths = $layer->getWidths();
            foreach ($layer->grid as $y => $symbols) {
                $cells = [];
                foreach ($symbols as $x => $symbol) {
                    $glyph = $layer->glyphs[$y][$x];
                    $cellWidth = $widths[$y][$x];
                    $source = $definition->getSourceIndex($glyph, $x, $y);
                    if ($source !== null && $cellWidth === 1
                        && ($layer === $base || $glyph !== ' ' || $definition->hasCellOverride($x, $y))) {
                        $cells[] = ['column' => $x, 'source' => $source];
                        if (++$tileCount > self::MAX_CELLS) { throw new InvalidArgumentException('Retained world exceeds the tile budget.'); }
                        $estimatedBytes += self::TILE_SOURCE_BYTES;
                        self::assertSourceBudget($estimatedBytes);
                    }
                }
                if ($cells !== []) {
                    $tileOperations[] = ['op' => 'worldTiles', 'id' => $id, 'layerId' => $layerId,
                        'rows' => [['row' => $y, 'cells' => $cells]]];
                }
            }
        }
        foreach ($grid as $y => $symbols) {
            $cells = [];
            foreach ($symbols as $x => $symbol) {
                $cells[] = [...$styled[$symbol], 'ownerLayerId' => PresentationLayerPolicy::getMapLayerId($layers->getGameplayLayerAt($x, $y))];
            }
            $rows[] = ['op' => 'worldRows', 'id' => $id, 'rows' => [['row' => $y, 'cells' => $cells]]];
        }
        return new self($id, [['op' => 'put', 'kind' => 'world', 'id' => $id,
            'value' => ['columns' => $width, 'rows' => $height, 'layers' => $metadata]], ...$rows, ...$tileOperations],
            [...array_column($metadata, 'id'), ...($layers->legacy ? [PresentationLayerPolicy::TERRAIN_ID] : [])], $estimatedBytes);
    }

    private static function assertSourceBudget(int $bytes): void
    {
        if ($bytes > self::MAX_SOURCE_BYTES) {
            throw new InvalidArgumentException('Retained world exceeds the source-memory budget; use retained screen text.');
        }
    }
}
