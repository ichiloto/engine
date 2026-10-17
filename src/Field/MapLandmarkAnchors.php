<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use InvalidArgumentException;

/** Source reference tables, without requiring an Editor to reconstruct a runtime map. */
final readonly class MapLandmarkAnchors
{
    /** @param array<string, MapLandmarkAnchor> $events
     *  @param array<string, MapLandmarkAnchor> $objects
     *  @param array<string, MapLandmarkAnchor> $triggers */
    public function __construct(public array $events = [], public array $objects = [], public array $triggers = [])
    {
        foreach ([$events, $objects, $triggers] as $table) {
            foreach ($table as $id => $anchor) {
                if ((!is_string($id) && !is_int($id)) || (string) $id === '' || !$anchor instanceof MapLandmarkAnchor) {
                    throw new InvalidArgumentException('Landmark reference tables require nonempty string ids and resolved MapLandmarkAnchor values.');
                }
            }
        }
    }

    /** Runtime adapter from MapSourceReader's resolved data; no trigger instantiation. */
    public static function readMap(array $map): self
    {
        $events = $objects = $triggers = [];
        foreach ($map['events'] ?? [] as $index => $event) {
            $id = $event['marker'] ?? '@' . $index;
            if (isset($events[$id])) { throw new InvalidArgumentException('Duplicate event anchor identity.'); }
            $events[$id] = new MapLandmarkAnchor(CellArea::fromArray($event['area']),
                is_a($event['class'] ?? '', TransferPlayerTrigger::class, true), $event['conditions'] ?? []);
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
        return new self($events, $objects, $triggers);
    }
}
