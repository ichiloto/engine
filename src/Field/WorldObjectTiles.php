<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;

/** Map-owned graphical tile identities; never a second occupancy source. */
final class WorldObjectTiles
{
    /** @return array<string, array<int, array<int, int>>> Layer, row, column, tile identity. */
    public static function read(mixed $data, WorldObjectPlacement $placement, MapLayerSet $layers,
        ?MapGraphics $graphics = null, ?array $declaredLayers = null): array
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || count($data) > MapGraphics::MAX_LAYERS) {
            throw new InvalidArgumentException('World object tiles2d requires named graphical layers and explicit cell lists.');
        }
        $names = $graphics === null ? ($declaredLayers === null ? null : array_keys($declaredLayers))
            : array_column($graphics->layers, 'name');
        $grid = $layers->getComposedGrid();
        $owned = [];
        foreach ($placement->cells as [$x, $y]) { $owned[$y][$x] = true; }
        $tiles = []; $count = 0;
        foreach ($data as $name => $cells) {
            if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $name) !== 1
                || ($names !== null && !in_array($name, $names, true))
                || !is_array($cells) || !array_is_list($cells) || $cells === []) {
                throw new InvalidArgumentException('World object tiles2d must name existing graphical layers with nonempty cell lists.');
            }
            foreach ($cells as $cell) {
                if (!is_array($cell) || array_diff(array_keys($cell), ['x', 'y', 'tile']) !== []
                    || !is_int($cell['x'] ?? null) || !is_int($cell['y'] ?? null) || !is_int($cell['tile'] ?? null)
                    || !isset($grid[$cell['y']][$cell['x']], $owned[$cell['y']][$cell['x']])
                    || isset($tiles[$name][$cell['y']][$cell['x']])
                    || ($cell['tile'] !== TileId::EMPTY && !TileId::isValid($cell['tile']))
                    || ++$count > WorldObjectPlacement::MAX_CELLS) {
                    throw new InvalidArgumentException('World object tiles2d requires at most 256 distinct valid tile cells inside its map footprint.');
                }
                $tiles[$name][$cell['y']][$cell['x']] = $cell['tile'];
            }
        }
        return $tiles;
    }
}
