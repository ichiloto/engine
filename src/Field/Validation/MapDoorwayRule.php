<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Field\WorldObjectPlacement;
use InvalidArgumentException;

/**
 * Painted doorways sit on their entrances, and entrances stay in front of the
 * art drawn around them.
 *
 * An entrance is a transfer event. A world object's resource may declare the
 * cells where its artwork paints an opening ({@see \Ichiloto\Engine\Field\WorldObjectResource::$doorway});
 * each placement's doorway must lie on an entrance, so art and the authored
 * map agree. A world object draws over anything standing on the rows of its
 * covered cells above its anchor row, its ground row, so no entrance may lie
 * there. Both read authored cells only: image size, pixels and the draw
 * layer decide nothing, and ordinary overhang above open floor stays legal.
 * Objects and entrances whose conditions provably never hold together do not
 * meet.
 */
final class MapDoorwayRule
{
    public const string RULE = 'object.doorway';

    private function __construct()
    {
    }

    /**
     * @param array $data The map's authored data, for its world objects.
     * @param list<array> $events Resolved event definitions ({@see MapEventResolver}).
     * @param list<list<string>> $grid The physical grid the placements are read against.
     * @param FieldPresentationCatalog|null $catalog Resources, when the map names any; unresolved resources are reported elsewhere.
     * @return list<MapValidationIssue>
     */
    public static function inspect(string $mapId, array $data, array $events, array $grid, ?FieldPresentationCatalog $catalog): array
    {
        $entries = is_array($data[WorldObjectDefinition::DATA_KEY] ?? null) ? $data[WorldObjectDefinition::DATA_KEY] : [];
        $entrances = [];
        foreach ($events as $event) {
            $class = $event['class'] ?? null;
            if (!is_string($class) || !is_a($class, TransferPlayerTrigger::class, true)) { continue; }
            $entrances[] = [
                'marker' => is_string($event['marker'] ?? null) ? $event['marker'] : null,
                'area' => CellArea::fromArray($event['area'], 'Event area'),
                'conditions' => is_array($event['conditions'] ?? null) ? $event['conditions'] : [],
            ];
        }
        $issues = [];
        foreach (array_is_list($entries) ? $entries : [] as $index => $entry) {
            try {
                $placement = WorldObjectPlacement::read(is_array($entry) ? $entry : [], $grid);
            } catch (InvalidArgumentException) {
                continue; // object.reference reports a malformed placement.
            }
            $meeting = array_values(array_filter($entrances, static fn(array $entrance): bool
                => !WorldConditionExclusion::proveExclusive($placement->conditions, $entrance['conditions'])));
            foreach ($meeting as $entrance) {
                $hidden = array_values(array_filter($placement->cells, static fn(array $cell): bool
                    => $cell[1] < $placement->y && $entrance['area']->containsCell($cell[0], $cell[1])));
                if ($hidden === []) { continue; }
                $label = $entrance['marker'] === null ? 'An entrance' : "Entrance '{$entrance['marker']}'";
                $issues[] = new MapValidationIssue($mapId, self::RULE, sprintf(
                    "%s at %d,%d stands behind world object '%s', whose ground row is %d: its artwork hides the player there.",
                    $label, $hidden[0][0], $hidden[0][1], $placement->id, $placement->y,
                ), $entrance['marker'], $hidden, ['events', $entrance['marker'] ?? ''],
                    "Place the entrance on the object's ground row or in front of it, where its doorway meets the ground.");
            }
            $doorway = self::readDoorway($entry, $catalog);
            if ($doorway === []) { continue; }
            $off = [];
            foreach ($doorway as $cell) {
                $x = $placement->x + $cell['x'];
                $y = $placement->y + $cell['y'];
                if (!array_any($meeting, static fn(array $entrance): bool => $entrance['area']->containsCell($x, $y))) { $off[] = [$x, $y]; }
            }
            if ($off === []) { continue; }
            $issues[] = new MapValidationIssue($mapId, self::RULE, sprintf(
                "World object '%s' paints its doorway at %d,%d, where no entrance is.", $placement->id, $off[0][0], $off[0][1],
            ), cells: $off, path: [WorldObjectDefinition::DATA_KEY, $index],
                hint: "Place the object so its painted doorway sits on the entrance, or correct the resource's doorway to match its art.");
        }
        return $issues;
    }

    /** @return list<array{x: int, y: int}> */
    private static function readDoorway(mixed $entry, ?FieldPresentationCatalog $catalog): array
    {
        $id = is_array($entry) ? ($entry['resource'] ?? null) : null;
        if (!is_string($id) || $catalog === null) { return []; }
        try {
            return $catalog->getResource($id)->doorway;
        } catch (InvalidArgumentException) {
            return []; // object.reference reports an unknown resource.
        }
    }
}
