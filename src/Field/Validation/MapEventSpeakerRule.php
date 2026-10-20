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
        $placements = self::readPlacements($data);
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
            $issues = [...$issues, ...self::inspectSpeakers($mapId, $placements, $speakers,
                CellArea::fromArray($event['area'], 'Event area'), is_array($event['conditions'] ?? null) ? $event['conditions'] : [],
                $marker === null ? 'This event' : "Event '{$marker}'", $marker, $path)];
        }
        return $issues;
    }

    /** @return array<string, list<NpcPlacement>> The map's NPC placements by stable id. */
    public static function readPlacements(array $data): array
    {
        $placements = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $entry) {
            $placement = NpcPlacement::fromArray($entry);
            if ($placement?->id !== null) { $placements[$placement->id][] = $placement; }
        }
        return $placements;
    }

    /**
     * Checks that each speaker stands beside an area whenever the scene there
     * can run: the event's cells, or the NPC whose page stages the scene.
     *
     * @param array<string, list<NpcPlacement>> $placements {@see self::readPlacements()}
     * @param list<string> $speakers
     * @param list<array<string, mixed>> $conditions When the scene can run.
     * @param list<int|string> $path
     * @return list<MapValidationIssue>
     */
    public static function inspectSpeakers(string $mapId, array $placements, array $speakers, CellArea $area, array $conditions,
        string $label, ?string $marker, array $path, string $scene = 'the event', string $place = "the event's area"): array
    {
        $issues = [];
        foreach ($speakers as $id) {
            if (!isset($placements[$id])) {
                $issues[] = new MapValidationIssue($mapId, self::RULE, "{$label} names speaker '{$id}', who is not an NPC on {$mapId}.",
                    $marker, $area->cells, $path, 'Choose a speaker from the NPCs on this map, or remove it.');
                continue;
            }
            $present = array_values(array_filter($placements[$id],
                static fn(NpcPlacement $page): bool => !WorldConditionExclusion::proveExclusive($conditions, $page->conditions)));
            if ($present === []) {
                $issues[] = new MapValidationIssue($mapId, self::RULE,
                    "{$label} names speaker '{$id}', who is never present while {$scene} can run.",
                    $marker, $area->cells, $path, "Give the speaker a page shown in that phase, beside {$place}.");
                continue;
            }
            foreach ($present as $page) {
                $standing = self::getStandingCells($page);
                $away = $standing === null ? [[$page->x, $page->y]] : array_values(array_filter($standing,
                    static fn(array $cell): bool => !self::isBeside($area, $cell[0], $cell[1])));
                if ($away === []) { continue; }
                $issues[] = new MapValidationIssue($mapId, self::RULE, sprintf(
                    $standing === null ? '%s is spoken by %s, who wanders freely from %d,%d.' : '%s is spoken by %s, who stands at %d,%d, away from %s.',
                    $label, $page->name, $page->x, $page->y, $scene,
                ), $marker, $away, $path, "Move the speaker beside {$place}, or stage {$scene} where the speaker stands.");
            }
        }
        return $issues;
    }

    /** @return list<array{int, int}>|null Where a placement may stand: its cell, its whole wander area, or null when it wanders freely. */
    public static function findStandingCells(NpcPlacement $page): ?array
    {
        return self::getStandingCells($page);
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
