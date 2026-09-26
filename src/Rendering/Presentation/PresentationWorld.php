<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\FieldViewport;
use InvalidArgumentException;

/**
 * Immutable, map-owned upload. Camera movement never visits its cells in PHP.
 * One wire cell is one map cell whatever its text; its text keeps the
 * terminal's MapCell::COLUMNS columns per cell.
 */
final readonly class PresentationWorld
{
    public const int MAX_LAYERS = 64;
    public const int MAX_CELLS = 1048576;
    public const int MAX_EXTENT = 16384;
    public const int MAX_SOURCE_BYTES = 67108864;
    public const int LAYER_SOURCE_BYTES = 8192;
    public const int CELL_SOURCE_BYTES = 64;

    /** @param list<array<string, mixed>> $operations */
    private function __construct(public string $id, public array $operations, public array $textLayerIds,
        public int $estimatedSourceBytes) {}

    public static function getFromLayers(MapLayerSet $layers, string $id = 'map'): self
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
        foreach ($grid as $y => $cells) {
            $wire = [];
            foreach ($cells as $x => $cell) {
                $wire[] = [...$styled[$cell], 'ownerLayerId' => PresentationLayerPolicy::getMapLayerId($layers->getGameplayLayerAt($x, $y))];
            }
            $rows[] = ['op' => 'worldRows', 'id' => $id, 'rows' => [['row' => $y, 'cells' => $wire]]];
        }
        return new self($id, [['op' => 'put', 'kind' => 'world', 'id' => $id,
            'value' => ['columns' => $width, 'rows' => $height, 'cellSize' => FieldViewport::CELL_SIZE,
                'cellColumns' => MapCell::COLUMNS, 'layers' => $metadata]], ...$rows],
            array_column($metadata, 'id'), $estimatedBytes);
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
        return ['glyph' => $glyph === '' ? MapCell::BLANK : $glyph,
            'foreground' => $foreground?->toArray(), 'background' => $background?->toArray()];
    }
}
