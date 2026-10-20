<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\NpcPage;
use Ichiloto\Engine\Field\NpcPages;
use Ichiloto\Engine\Field\NpcPlacement;
use InvalidArgumentException;

/**
 * What talking to an NPC does belongs to the NPC.
 *
 * `npc.page`: each NPC's pages read as {@see NpcPages} reads them; a page
 * staging other speakers needs them on the map and present whenever the page
 * can run ({@see MapEventSpeakerRule}: the scene happens beside the NPC the
 * player talks to, so their distance is the author's staging); and no page is hidden behind an earlier
 * reusable page that runs whenever it could, since talking runs the first
 * available page. The shadow check compares authored conditions only, so it
 * reports what it can prove and stays silent otherwise.
 *
 * `event.npc-interaction`: an event the player starts by action, whose
 * script, Cinematic or lines an NPC delivers (it names NPC speakers), sits on
 * a cell apart from the NPC, so talking to the NPC itself says something
 * else. It belongs on the delivering NPC's pages, with its cue. Scenes that
 * start on arrival are not conversations. An event bound to a subject, a
 * world object or a named map area, is that thing's or place's interaction
 * (a terminal, a reception counter, a safe position); its speakers answer
 * for it. Shop and inn events stay with the
 * counter or fixture they are authored on; a page offers either service
 * through the `shop` and `inn` script commands when the NPC should own it.
 */
final class MapNpcInteractionRule
{
    public const string PAGE_RULE = 'npc.page';
    public const string EVENT_RULE = 'event.npc-interaction';
    /** Event actions an NPC page carries: shared scripts, Cinematics and lines. */
    public const array PAGE_ACTION_TRIGGERS = [ScriptEventTrigger::class, CinematicEventTrigger::class, DialogueEventTrigger::class];

    private function __construct()
    {
    }

    /**
     * @param list<array> $events Resolved event definitions ({@see MapEventResolver}).
     * @return list<MapValidationIssue>
     */
    public static function inspect(string $mapId, array $data, array $events): array
    {
        return [...self::inspectPages($mapId, $data), ...self::inspectEvents($mapId, $data, $events)];
    }

    /** @return list<MapValidationIssue> */
    public static function inspectPages(string $mapId, array $data): array
    {
        $placements = MapEventSpeakerRule::readPlacements($data);
        $issues = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $index => $entry) {
            $placement = NpcPlacement::fromArray($entry);
            if ($placement === null) { continue; }
            $path = ['npcs', $index, 'dialogue'];
            try {
                $pages = NpcPages::fromEntry($entry);
            } catch (InvalidArgumentException $error) {
                $issues[] = new MapValidationIssue($mapId, self::PAGE_RULE, "NPC '{$placement->name}': {$error->getMessage()}",
                    cells: [[$placement->x, $placement->y]], path: $path, hint: "Correct the NPC's pages in the Editor.");
                continue;
            }
            if (array_filter((array) ($entry['script'] ?? []), is_array(...)) !== []
                && array_filter((array) ($entry['dialogue'] ?? []), is_array(...)) !== []) {
                $issues[] = new MapValidationIssue($mapId, self::PAGE_RULE,
                    "NPC '{$placement->name}' has a top-level script, so its dialogue never runs.",
                    cells: [[$placement->x, $placement->y]], path: $path, hint: 'Author the script and the dialogue as pages.');
            }
            foreach ($pages->pages as $page) {
                $label = sprintf("%s's page %d", $placement->name, $page->index + 1);
                if ($page->speakers !== []) {
                    $issues = [...$issues, ...MapEventSpeakerRule::inspectSpeakers($mapId, $placements,
                        array_values(array_diff($page->speakers, [$placement->id])), null,
                        [...$placement->conditions, ...$page->conditions], $label, null, [...$path, $page->index, 'speakers'],
                        'the page', $placement->name)];
                }
                $shadow = self::findShadow($pages, $page);
                if ($shadow !== null) {
                    $issues[] = new MapValidationIssue($mapId, self::PAGE_RULE, sprintf(
                        '%s never runs: page %d runs whenever it could%s.', $label, $shadow->index + 1,
                        $page->cue !== null || $page->hasAction ? ', so its scene and cue never happen' : '',
                    ), cells: [[$placement->x, $placement->y]], path: [...$path, $page->index],
                        hint: 'Put the most advanced story page first, or remove the page that can never run.');
                }
            }
        }
        return $issues;
    }

    /** @return list<MapValidationIssue> */
    public static function inspectEvents(string $mapId, array $data, array $events): array
    {
        $names = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $entry) {
            $placement = NpcPlacement::fromArray($entry);
            if ($placement?->id !== null) { $names[$placement->id] = $placement->name; }
        }
        $issues = [];
        foreach ($events as $event) {
            $class = $event['class'] ?? null;
            if (!is_string($class) || !array_any(self::PAGE_ACTION_TRIGGERS, static fn(string $type): bool => is_a($class, $type, true))) {
                continue;
            }
            if (strtolower(trim(strval($event['data']['mode'] ?? ''))) === ScriptEventTrigger::MODE_AUTO) { continue; }
            if (is_array($event['subject'] ?? null)) { continue; }
            try {
                $speakers = array_values(array_filter(MapEventSpeakerRule::readSpeakers($event), static fn(string $id): bool => isset($names[$id])));
            } catch (InvalidArgumentException) {
                continue; // event.speaker reports malformed speakers.
            }
            if ($speakers === []) { continue; }
            $marker = is_string($event['marker'] ?? null) ? $event['marker'] : null;
            $owner = $names[$speakers[0]];
            $issues[] = new MapValidationIssue($mapId, self::EVENT_RULE, sprintf(
                "%s is %s's to deliver, but the player starts it from its own cell; talking to %s does something else.",
                $marker === null ? 'This event' : "Event '{$marker}'", $owner, $owner,
            ), $marker, is_array($event['area']['cells'] ?? null) ? $event['area']['cells'] : [], ['events', $marker ?? ''],
                "Move it onto {$owner}'s pages, with its conditions and cue, and remove the separate event.");
        }
        return $issues;
    }

    /** The earlier reusable page that runs whenever this one could, if the authored conditions prove one. */
    private static function findShadow(NpcPages $pages, NpcPage $page): ?NpcPage
    {
        $own = array_map(self::describeCondition(...), $page->conditions);
        foreach ($pages->pages as $earlier) {
            if ($earlier->index >= $page->index) { break; }
            if (!$earlier->reusable || !$earlier->isTalkable) { continue; }
            if (array_diff(array_map(self::describeCondition(...), $earlier->conditions), $own) === []) { return $earlier; }
        }
        return null;
    }

    private static function describeCondition(array $condition): string
    {
        ksort($condition);
        if (($condition['negate'] ?? false) === false) { unset($condition['negate']); }
        return json_encode($condition, JSON_THROW_ON_ERROR);
    }
}
