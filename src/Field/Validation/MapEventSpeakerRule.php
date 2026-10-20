<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Events\MapEventDefinition;
use Ichiloto\Engine\Field\NpcPlacement;
use InvalidArgumentException;

/**
 * An event's speakers stand beside it whenever it can run, so the player sees
 * who is talking.
 *
 * Speakers are the stable NPC ids an event names ({@see MapEventDefinition}).
 * Every authored page of a speaker that may show while the event's conditions
 * hold (its conditions are not proven exclusive by
 * {@see WorldConditionExclusion}) must stand within one cell of the event's
 * area, in any direction; a wandering page must keep its whole wander area
 * there. At least one page must be able to show. The rule reads the map as
 * authored and runs nothing, so a script that walks a speaker into place is
 * expressed as a page conditioned on that phase.
 */
final class MapEventSpeakerRule
{
    public const string RULE = 'event.speaker';
    /** How far, in cells and in any direction, a speaker may stand from the event's area. */
    public const int REACH = 1;

    private function __construct()
    {
    }

    /**
     * Reads an event's authored speakers.
     *
     * @return list<string> Distinct NPC ids, or an empty list when none are named.
     * @throws InvalidArgumentException When speakers is not a list of 1..8 distinct NPC ids.
     */
    public static function readSpeakers(array $definition): array
    {
        if (!array_key_exists('speakers', $definition)) { return []; }
        $speakers = $definition['speakers'];
        if (!is_array($speakers) || !array_is_list($speakers) || $speakers === [] || count($speakers) > MapEventDefinition::MAX_SPEAKERS) {
            throw new InvalidArgumentException('Event speakers must be a list of 1..' . MapEventDefinition::MAX_SPEAKERS . ' NPC ids.');
        }
        $seen = [];
        foreach ($speakers as $id) {
            if (!is_string($id) || trim($id) === '' || trim($id) !== $id || isset($seen[$id])) {
                throw new InvalidArgumentException('Event speakers must name distinct NPC ids, each once.');
            }
            $seen[$id] = true;
        }
        return $speakers;
    }

    /**
     * @param array $data The map's authored data, for its NPCs.
     * @param list<array> $events Resolved event definitions ({@see MapEventResolver}), with area and effective conditions.
     * @return list<MapValidationIssue>
     */
    public static function inspect(string $mapId, array $data, array $events): array
    {
        $pages = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $entry) {
            $placement = NpcPlacement::fromArray($entry);
            if ($placement?->id !== null) { $pages[$placement->id][] = $placement; }
        }
        $issues = [];
        foreach ($events as $event) {
            $marker = is_string($event['marker'] ?? null) ? $event['marker'] : null;
            $path = ['events', $marker ?? '', 'speakers'];
            try {
                $speakers = self::readSpeakers($event);
            } catch (InvalidArgumentException $error) {
                $issues[] = new MapValidationIssue($mapId, self::RULE, $error->getMessage(), $marker, path: $path,
                    hint: 'Choose each speaker from the NPCs on this map.');
                continue;
            }
            if ($speakers === []) { continue; }
            $area = CellArea::fromArray($event['area'], 'Event area');
            $conditions = is_array($event['conditions'] ?? null) ? $event['conditions'] : [];
            $label = $marker === null ? 'This event' : "Event '{$marker}'";
            foreach ($speakers as $id) {
                $present = array_values(array_filter($pages[$id] ?? [],
                    static fn(NpcPlacement $page): bool => !WorldConditionExclusion::proveExclusive($conditions, $page->conditions)));
                if (!isset($pages[$id])) {
                    $issues[] = new MapValidationIssue($mapId, self::RULE, "{$label} names speaker '{$id}', who is not an NPC on {$mapId}.",
                        $marker, $area->cells, $path, 'Choose a speaker from the NPCs on this map, or remove it.');
                    continue;
                }
                if ($present === []) {
                    $issues[] = new MapValidationIssue($mapId, self::RULE,
                        "{$label} names speaker '{$id}', who is never present while the event can run.",
                        $marker, $area->cells, $path, "Give the speaker a page shown in the event's phase, beside its area.");
                    continue;
                }
                foreach ($present as $page) {
                    $standing = self::getStandingCells($page);
                    $away = $standing === null ? [[$page->x, $page->y]] : array_values(array_filter($standing,
                        static fn(array $cell): bool => !self::isBeside($area, $cell[0], $cell[1])));
                    if ($away === []) { continue; }
                    $issues[] = new MapValidationIssue($mapId, self::RULE, sprintf(
                        $standing === null ? '%s is spoken by %s, who wanders freely from %d,%d.' : '%s is spoken by %s, who stands at %d,%d, away from the event.',
                        $label, $page->name, $page->x, $page->y,
                    ), $marker, $away, $path, "Move the speaker beside the event's area, or bind the event to where the speaker stands.");
                }
            }
        }
        return $issues;
    }

    /** @return list<array{int, int}>|null Where a page may stand: its cell, its whole wander area, or null when it wanders freely. */
    private static function getStandingCells(NpcPlacement $page): ?array
    {
        if (!$page->wanders) { return [[$page->x, $page->y]]; }
        if ($page->wanderArea === null) { return null; }
        $cells = [];
        ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] = $page->wanderArea;
        for ($row = $y; $row < $y + $height; $row++) {
            for ($column = $x; $column < $x + $width; $column++) { $cells[] = [$column, $row]; }
        }
        return $cells;
    }

    private static function isBeside(CellArea $area, int $x, int $y): bool
    {
        foreach ($area->cells as [$cellX, $cellY]) {
            if (max(abs($cellX - $x), abs($cellY - $y)) <= self::REACH) { return true; }
        }
        return false;
    }
}
