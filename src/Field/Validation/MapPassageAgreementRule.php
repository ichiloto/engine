<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Field\WorldObjectPlacement;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\PngCellCoverage;
use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;
use RuntimeException;

/**
 * What a map shows agrees with what it lets the player do.
 *
 * Passage comes from the map's collision ({@see MapPhysicalOccupancy}), and
 * its graphics are drawn independently of it, so nothing else keeps the two
 * telling the same story. This rule compares them cell by cell, as the
 * graphical field draws the map:
 *
 * - an invisible wall: a solid cell that shows only walkable ground;
 * - a walk-through: a walkable cell that shows water or a barrier, or that a
 *   world object's opaque art covers on the object's ground rows;
 * - an under-covered footprint: a world object whose art covers less than
 *   half of its solid cells' area and leaves some of them bare.
 *
 * Art covers a cell when it stands in the cell's middle, where the player
 * stops ({@see PngCellCoverage::isCentreCovered()}): a thin post does, a
 * sliver along the cell's edge does not.
 *
 * What a cell shows is the topmost tile drawn there that makes a claim about
 * the ground, classified by its tileset ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetAppearance}),
 * and the opaque art of the world objects drawn over it, read from their
 * current images ({@see PngCellCoverage}). A cell is not judged when what it
 * shows is unknown: a tile no class covers, a terminal glyph still showing,
 * an NPC, or art that cannot be read. Entrances and painted doorways are
 * {@see MapDoorwayRule}'s, and an object's art above its top ground row is
 * ordinary overhang, so neither is judged here. A solid cell under art drawn
 * over the player, such as a roof, shows more than ground. A tile layer drawn
 * half a cell off counts as an obstacle over every cell it overlaps but never
 * makes a walkable cell a walk-through.
 *
 * A walkable cell is judged unless it lies in a pocket nobody enters,
 * walled in on every side with no event or NPC in it, as a terminal map draws
 * a wall's face as a closed strip. Otherwise a cell is judged whether or not
 * the player reaches it today: a mismatch out of reach becomes one they meet
 * when a route or entrance moves, or when an entrance refuses them and they
 * step on.
 *
 * Findings are warnings for the author: artists own composition, so the map
 * plays, saves and loads unchanged.
 */
final class MapPassageAgreementRule
{
    public const string INVISIBLE_WALL = 'passage.invisible-wall';
    public const string WALK_THROUGH = 'passage.walk-through';
    public const string UNDER_COVERED = 'passage.footprint';
    public const string UNJUDGED = 'passage.unjudged';
    /** The share of a walkable cell that a world object's art may cover on its ground rows before it reads as solid. */
    public const float OBJECT_SHOWN = .6;
    /** The share of its solid cells' area an object's art must cover. */
    public const float FOOTPRINT_COVERED = .5;

    private function __construct()
    {
    }

    /**
     * @param array $data The map's authored data: its world objects and NPCs.
     * @param list<array> $events Resolved event definitions ({@see MapEventResolver}).
     * @param FieldPresentationCatalog|null $catalog The resources the map's world objects name; unresolved ones are reported elsewhere.
     * @return list<MapValidationIssue>
     */
    public static function inspect(string $mapId, array $data, MapLayerSet $layers, MapPhysicalOccupancy $occupancy,
        MapGraphics $graphics, string $assetRoot, array $events, ?FieldPresentationCatalog $catalog): array
    {
        $grid = $occupancy->collisionGrid;
        $issues = [];
        $skipped = self::findSkippedCells($data, $events, $layers, $catalog);
        try {
            $objects = WorldObjectDefinition::readMap($data, $layers, $graphics, catalog: $catalog);
        } catch (InvalidArgumentException|RuntimeException) {
            $objects = []; // object.reference reports why; their cells are judged by their tiles alone.
        }

        // Every object's opaque art, cell by cell: all of it, and only what stands on its ground rows.
        $shown = $grounded = $covered = [];
        foreach ($objects as $index => $object) {
            $placement = self::readPlacement($data, $index, $layers);
            $footprint = self::findFootprint($object, $placement, $grid, $layers);
            $art = self::measureArt($object, $assetRoot);
            if (is_string($art)) {
                $issues[] = new MapValidationIssue($mapId, self::UNJUDGED, "World object '{$object->id}' art could not be read: {$art}",
                    cells: $placement?->cells ?? [[$object->x, $object->y]], path: [WorldObjectDefinition::DATA_KEY, $index],
                    hint: 'Its cells are not judged until its art can be read.');
                foreach ($placement?->cells ?? [[$object->x, $object->y]] as [$x, $y]) { $skipped[$y][$x] = true; }
                continue;
            }
            if ($art === null) { continue; } // Explicitly drawn without art: its tiles show.
            $issues = [...$issues, ...self::checkFootprint($mapId, $index, $object->id, $footprint, $art)];
            // A conditional object is absent at times; then the tiles beneath it are what shows.
            if ($object->conditions !== []) { continue; }
            $groundTop = min([$object->y, ...array_column($footprint, 1)]);
            foreach ($art as $y => $row) {
                foreach ($row as $x => $mask) {
                    $shown[$y][$x] = isset($shown[$y][$x]) ? $shown[$y][$x] | $mask : $mask;
                    if ($y >= $groundTop) {
                        $grounded[$y][$x] = isset($grounded[$y][$x]) ? $grounded[$y][$x] | $mask : $mask;
                    }
                }
            }
            foreach ($object->coverage['glyphs'] ?? [] as $layer => $cells) {
                foreach ($cells as $y => $columns) {
                    foreach ($columns as $x => $_) { $covered[$layer][$y][$x] = true; }
                }
            }
        }
        // A cell still showing its terminal glyph shows no tiles to judge.
        foreach ($graphics->getShownGlyphCells($layers, $assetRoot) as $cell) {
            if (!isset($covered[$cell['layer']][$cell['y']][$cell['x']])) { $skipped[$cell['y']][$cell['x']] = true; }
        }

        [$tiles, $overdrawn] = self::readTileAppearance($graphics, $assetRoot);
        $sealed = self::findSealedCells($grid, $data, $events);
        $walls = $through = [];
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $value) {
                if (isset($skipped[$y][$x])) { continue; }
                $type = CollisionType::from($value);
                if ($type === CollisionType::NPC) { continue; }
                if ($type->blocksMovement()) {
                    if (PngCellCoverage::isCentreCovered($shown[$y][$x] ?? null)
                        || isset($overdrawn[$y][$x])) {
                        continue;
                    }
                    if (($tiles[$y][$x] ?? null) === TileAppearance::GROUND) { $walls[] = [$x, $y]; }
                    continue;
                }
                if (isset($sealed[$y][$x])) { continue; }
                if (PngCellCoverage::getShare($grounded[$y][$x] ?? null) >= self::OBJECT_SHOWN) {
                    $through['world object art'][] = [$x, $y];
                } elseif (!PngCellCoverage::isCentreCovered($shown[$y][$x] ?? null)
                    && ($tiles[$y][$x] ?? null)?->showsObstacle()) {
                    $through[$tiles[$y][$x] === TileAppearance::WATER ? 'water' : 'a barrier'][] = [$x, $y];
                }
            }
        }
        if ($walls !== []) {
            $issues[] = new MapValidationIssue($mapId, self::INVISIBLE_WALL, 'Solid cells show only walkable ground: the player meets an invisible wall.',
                cells: $walls, hint: 'Paint what blocks the player there, such as water, a cliff, a wall or an object, or make the cells walkable.');
        }
        foreach ($through as $shows => $cells) {
            $issues[] = new MapValidationIssue($mapId, self::WALK_THROUGH, "Walkable cells show {$shows}: the player walks through it.",
                cells: $cells, hint: 'Make the cells solid, or paint walkable ground there.');
        }
        return $issues;
    }

    /**
     * Walkable pockets nobody enters: walled in by solid cells on every side,
     * away from the map's edge, with no event or NPC in them, as a terminal
     * map draws a wall's face as a closed strip. What they show is never
     * walked through.
     *
     * @param list<list<int>> $grid
     * @param list<array> $events
     * @return array<int, array<int, true>>
     */
    private static function findSealedCells(array $grid, array $data, array $events): array
    {
        $occupied = [];
        foreach ($events as $event) {
            try {
                foreach (CellArea::fromArray($event['area'] ?? null, 'Event area')->cells as [$x, $y]) { $occupied[$y][$x] = true; }
            } catch (InvalidArgumentException) {
                continue; // event.definition reports it.
            }
        }
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $entry) {
            $npc = NpcPlacement::fromArray($entry);
            if ($npc !== null) { $occupied[$npc->y][$npc->x] = true; }
        }
        $walkable = static fn(int $x, int $y): bool => isset($grid[$y][$x]) && !CollisionType::from($grid[$y][$x])->blocksMovement();
        $sealed = $seen = [];
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $_) {
                if (isset($seen[$y][$x]) || !$walkable($x, $y)) { continue; }
                // One pocket of walkable cells, and whether anything opens it.
                $pocket = [];
                $open = false;
                $queue = [[$x, $y]];
                $seen[$y][$x] = true;
                while ($queue !== []) {
                    [$cellX, $cellY] = array_pop($queue);
                    $pocket[] = [$cellX, $cellY];
                    $open = $open || isset($occupied[$cellY][$cellX]) || $cellY === 0 || $cellY === count($grid) - 1
                        || $cellX === 0 || $cellX === count($grid[$cellY]) - 1;
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        [$nextX, $nextY] = [$cellX + $dx, $cellY + $dy];
                        // A cell beside a ragged row's end lies outside the map, so the pocket reaches its edge.
                        $open = $open || ($nextY >= 0 && $nextY < count($grid) && $nextX >= 0 && !isset($grid[$nextY][$nextX]));
                        if (isset($seen[$nextY][$nextX]) || !$walkable($nextX, $nextY)) { continue; }
                        $seen[$nextY][$nextX] = true;
                        $queue[] = [$nextX, $nextY];
                    }
                }
                if (!$open) {
                    foreach ($pocket as [$cellX, $cellY]) { $sealed[$cellY][$cellX] = true; }
                }
            }
        }
        return $sealed;
    }

    /**
     * Cells another rule owns or that show something other than the map:
     * entrances, painted doorways and NPCs.
     *
     * @return array<int, array<int, true>>
     */
    private static function findSkippedCells(array $data, array $events, MapLayerSet $layers, ?FieldPresentationCatalog $catalog): array
    {
        $skipped = [];
        foreach ($events as $event) {
            $class = $event['class'] ?? null;
            if (!is_string($class) || !is_a($class, TransferPlayerTrigger::class, true)) { continue; }
            try {
                foreach (CellArea::fromArray($event['area'] ?? null, 'Event area')->cells as [$x, $y]) { $skipped[$y][$x] = true; }
            } catch (InvalidArgumentException) {
                continue; // event.definition reports it.
            }
        }
        $entries = is_array($data[WorldObjectDefinition::DATA_KEY] ?? null) ? $data[WorldObjectDefinition::DATA_KEY] : [];
        foreach (array_is_list($entries) ? $entries : [] as $index => $entry) {
            $placement = self::readPlacement($data, $index, $layers);
            $id = is_array($entry) ? ($entry['resource'] ?? null) : null;
            if ($placement === null || !is_string($id) || $catalog === null) { continue; }
            try {
                foreach ($catalog->getResource($id)->doorway as $door) {
                    $skipped[$placement->y + $door['y']][$placement->x + $door['x']] = true;
                }
            } catch (InvalidArgumentException) {
                continue; // object.reference reports an unknown resource.
            }
        }
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $entry) {
            $npc = NpcPlacement::fromArray($entry);
            if ($npc !== null) { $skipped[$npc->y][$npc->x] = true; }
        }
        return $skipped;
    }

    private static function readPlacement(array $data, int $index, MapLayerSet $layers): ?WorldObjectPlacement
    {
        $entry = $data[WorldObjectDefinition::DATA_KEY][$index] ?? null;
        try {
            return is_array($entry) ? WorldObjectPlacement::read($entry, $layers->getComposedGrid()) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The object's solid cells: those it covers on the map and those its
     * resource's occupancy stamp names, where the map's collision blocks
     * movement. Its painted doorway is never part of it.
     *
     * @param list<list<int>> $grid
     * @return list<array{0: int, 1: int}>
     */
    private static function findFootprint(WorldObjectDefinition $object, ?WorldObjectPlacement $placement, array $grid,
        MapLayerSet $layers): array
    {
        $cells = [];
        foreach ($placement?->cells ?? [[$object->x, $object->y]] as [$x, $y]) { $cells[$y][$x] = true; }
        if ($object->resource !== null) {
            try {
                foreach ($object->resource->resolveOccupancyStamp($layers, $object->x, $object->y) as $cell) {
                    $cells[$cell['y']][$cell['x']] = true;
                }
            } catch (InvalidArgumentException) {
                // A stamp beyond the map is the authoring tool's to refuse.
            }
            foreach ($object->resource->doorway as $door) { unset($cells[$object->y + $door['y']][$object->x + $door['x']]); }
        }
        $footprint = [];
        foreach ($cells as $y => $columns) {
            foreach ($columns as $x => $_) {
                $value = $grid[$y][$x] ?? null;
                if ($value !== null && CollisionType::from($value) !== CollisionType::NPC && CollisionType::from($value)->blocksMovement()) {
                    $footprint[] = [$x, $y];
                }
            }
        }
        usort($footprint, static fn(array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        return $footprint;
    }

    /**
     * The object's resting art, cell by cell; null when it is explicitly drawn
     * without art or is a sign, whose board the field composes itself; why it
     * cannot be read otherwise.
     *
     * @return array<int, array<int, string>>|string|null
     */
    private static function measureArt(WorldObjectDefinition $object, string $assetRoot): array|string|null
    {
        if ($object->sprites === null || $object->resource?->sign !== null) { return null; }
        $role = new FieldSpriteRole($object->sprites, $assetRoot, "Passage agreement {$object->id}");
        try {
            $frame = $role->getStillFrame($object->pivot);
        } finally {
            $role->release();
        }
        if ($frame === null) { return 'its field artwork is unavailable.'; }
        $result = PngCellCoverage::measure($assetRoot, $frame, $object->x, $object->y);
        return $result['diagnostic'] ?? $result['cells'];
    }

    /**
     * An object whose art covers less than half of its solid cells' area and
     * leaves some of them bare. A slender post or trunk standing in its cell
     * covers little of it but leaves nothing bare.
     *
     * @param list<array{0: int, 1: int}> $footprint
     * @param array<int, array<int, string>> $art
     * @return list<MapValidationIssue>
     */
    private static function checkFootprint(string $mapId, int $index, string $id, array $footprint, array $art): array
    {
        if ($footprint === []) { return []; }
        $bare = array_values(array_filter($footprint, static fn(array $cell): bool
            => !PngCellCoverage::isCentreCovered($art[$cell[1]][$cell[0]] ?? null)));
        $share = array_sum(array_map(static fn(array $cell): float => PngCellCoverage::getShare($art[$cell[1]][$cell[0]] ?? null),
            $footprint)) / count($footprint);
        if ($bare === [] || $share >= self::FOOTPRINT_COVERED) { return []; }
        return [new MapValidationIssue($mapId, self::UNDER_COVERED, sprintf(
            "World object '%s' art covers %d%% of its %d solid cells and leaves %d of them showing open ground.", $id,
            (int) round($share * 100), count($footprint), count($bare)), cells: $bare, path: [WorldObjectDefinition::DATA_KEY, $index],
            hint: 'Fit the art to its footprint, or make solid only the cells its art covers.')];
    }

    /**
     * What each cell's tiles show: the topmost tile drawn there that makes a
     * claim about the ground, or null when that tile's class is unknown or
     * nothing claims it. And the cells where tiles show more than ground
     * whatever that claim is: art drawn over the player, or an obstacle drawn
     * half a cell into the cell.
     *
     * @return array{0: array<int, array<int, ?TileAppearance>>, 1: array<int, array<int, true>>}
     */
    private static function readTileAppearance(MapGraphics $graphics, string $assetRoot): array
    {
        $usable = $graphics->tileset->getUsableSheets($assetRoot)['sheets'] ?? [];
        $tiles = $overdrawn = [];
        foreach (array_reverse($graphics->layers) as $layer) {
            [$offsetX, $offsetY] = $graphics->offsets[$layer->name] ?? [0.0, 0.0];
            $offset = $offsetX !== 0.0 || $offsetY !== 0.0;
            foreach ($layer->tiles as $y => $row) {
                foreach ($row as $x => $id) {
                    // A tile whose sheet cannot be drawn shows nothing.
                    if ($id === TileId::EMPTY || !isset($usable[TileId::getSheet($id)?->value ?? ''])) { continue; }
                    $class = $graphics->tileset->classifyAppearance($id);
                    if ($class === TileAppearance::OVERHEAD || ($offset && $class?->showsObstacle())) {
                        foreach (array_unique([(int) floor($x + $offsetX), (int) ceil($x + $offsetX)]) as $cellX) {
                            foreach (array_unique([(int) floor($y + $offsetY), (int) ceil($y + $offsetY)]) as $cellY) {
                                $overdrawn[$cellY][$cellX] = true;
                            }
                        }
                    }
                    // Art over the player and tiles drawn between cells make no claim about the cell's own ground.
                    if ($class === TileAppearance::OVERHEAD || $offset || array_key_exists($x, $tiles[$y] ?? [])) { continue; }
                    $tiles[$y][$x] = $class;
                }
            }
        }
        return [$tiles, $overdrawn];
    }
}
