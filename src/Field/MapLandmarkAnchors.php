<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Events\Triggers\PassageEventTrigger;
use InvalidArgumentException;

/** Source reference tables, without requiring an Editor to reconstruct a runtime map. */
final readonly class MapLandmarkAnchors
{
    /** @param array<string, MapLandmarkAnchor> $events
     *  @param array<string, MapLandmarkAnchor> $objects
     *  @param array<string, MapLandmarkAnchor> $triggers
     *  @param array<string, MapLandmarkAnchor> $areas */
    public function __construct(public array $events = [], public array $objects = [], public array $triggers = [], public array $areas = [])
    {
        foreach ([$events, $objects, $triggers, $areas] as $table) {
            foreach ($table as $id => $anchor) {
                if ((!is_string($id) && !is_int($id)) || (string) $id === '' || !$anchor instanceof MapLandmarkAnchor) {
                    throw new InvalidArgumentException('Landmark reference tables require nonempty string ids and resolved MapLandmarkAnchor values.');
                }
            }
        }
    }

    /** Runtime adapter from MapSourceReader's resolved data; no trigger instantiation. */
    public static function readMap(array $map, ?array $grid = null): self
    {
        $events = $objects = $triggers = $areas = [];
        if (array_key_exists('areas', $map) && $map['areas'] !== []) {
            if ($grid === null) { throw new InvalidArgumentException('Named area anchors require the physical map grid.'); }
            foreach (MapAreaCatalog::readMap($map, $grid)->areas as $id => $area) {
                $areas[$id] = new MapLandmarkAnchor($area);
            }
        }
        foreach ($map['events'] ?? [] as $index => $event) {
            $id = $event['marker'] ?? '@' . $index;
            if (isset($events[$id])) { throw new InvalidArgumentException('Duplicate event anchor identity.'); }
            $passage = is_a($event['class'] ?? '', PassageEventTrigger::class, true);
            if ($passage) { PassageEventTrigger::validateDefinition($event, $map['id'] ?? null); }
            $events[$id] = new MapLandmarkAnchor(CellArea::fromArray($event['area']),
                is_a($event['class'] ?? '', TransferPlayerTrigger::class, true),
                $passage ? [['type' => 'map_event', 'name' => $map['id'], 'marker' => $id]] : ($event['conditions'] ?? []),
                $passage);
        }
        foreach ($map['worldObjects'] ?? [] as $object) {
            if (isset($objects[$object['id']])) { throw new InvalidArgumentException('Duplicate object anchor identity.'); }
            $objects[$object['id']] = new MapLandmarkAnchor(CellArea::fromCells([
                [$object['anchor']['x'] ?? null, $object['anchor']['y'] ?? null],
            ]));
        }
        foreach ($map['triggers'] ?? [] as $index => $trigger) {
            $id = $trigger['id'] ?? '@' . $index;
            if (isset($triggers[$id])) { throw new InvalidArgumentException('Duplicate legacy trigger anchor identity.'); }
            $area = $trigger['trigger_area'] ?? [];
            $triggers[$id] = new MapLandmarkAnchor(CellArea::fromArray([
                'x' => $area['x'] ?? 0, 'y' => $area['y'] ?? 0,
                'width' => $area['width'] ?? 1, 'height' => $area['height'] ?? 1,
            ]), true);
        }
        return new self($events, $objects, $triggers, $areas);
    }
}
