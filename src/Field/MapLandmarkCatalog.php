<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Localization\LocalizedText;
use InvalidArgumentException;

/** Validates source references before a destination can replace the active map. */
final readonly class MapLandmarkCatalog
{
    public const int MAX_LANDMARKS = 256;
    public const string ID_PATTERN = '/\A[a-z][a-z0-9._-]{0,63}\z/';
    public const int DEFAULT_APPROACH_DISTANCE = 3;
    public const int MIN_APPROACH_DISTANCE = 0;
    public const int MAX_APPROACH_DISTANCE = 64;
    public const int MAX_REVEAL_CONDITIONS = 32;

    /** @param list<MapLandmarkDefinition> $landmarks */
    private function __construct(public array $landmarks) {}

    /** Reads resolved event areas from MapSourceReader; never uses destination spawn points. */
    public static function readMap(array $map, array $tiles): self
    {
        $entries = array_key_exists('landmarks', $map) ? $map['landmarks'] : [];
        if (!is_array($entries)) { throw new InvalidArgumentException('landmarks must be a list.'); }
        return self::readDeclarations($entries, MapLandmarkAnchors::readMap($map), array_map('count', $tiles));
    }

    /** Runtime and unsaved Editor models share this validator, including ragged geometry.
     *  @param list<int> $rowWidths Number of real logical cells on each map row. */
    public static function readDeclarations(array $entries, MapLandmarkAnchors $anchors, array $rowWidths): self
    {
        if (!array_is_list($entries) || count($entries) > self::MAX_LANDMARKS) {
            throw new InvalidArgumentException('landmarks must be a list of at most 256 declarations.');
        }
        if (!array_is_list($rowWidths) || $rowWidths === []
            || !array_all($rowWidths, static fn($width) => is_int($width) && $width >= 0)) {
            throw new InvalidArgumentException('Landmark map geometry requires a nonempty list of nonnegative row widths.');
        }
        foreach ([$anchors->events, $anchors->objects, $anchors->triggers] as $table) {
            foreach ($table as $anchor) {
                self::assertAreaIsOnMap($anchor->area, $rowWidths);
                WorldConditionEvaluator::validateAll($anchor->accessConditions, 'landmark anchor access');
            }
        }
        $landmarks = $ids = $claimedEvents = $claimedTriggers = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry),
                ['id', 'kind', 'anchor', 'name', 'secret', 'approachDistance', 'revealConditions']) !== []) {
                throw new InvalidArgumentException('A landmark accepts id, kind, anchor, name, secret, approachDistance and revealConditions.');
            }
            $id = $entry['id'] ?? null;
            if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1 || isset($ids[$id])) {
                throw new InvalidArgumentException('Landmarks require unique stable lowercase ids of 1..64 characters.');
            }
            $kind = is_string($entry['kind'] ?? null) ? MapLandmarkKind::tryFrom($entry['kind']) : null;
            if ($kind === null) { throw new InvalidArgumentException("Landmark '{$id}' requires a supported kind."); }
            $anchor = $entry['anchor'] ?? null;
            if (!is_array($anchor) || count($anchor) !== 1 || array_diff(array_keys($anchor), ['event', 'object', 'trigger']) !== []
                || !is_string(array_values($anchor)[0])) {
                throw new InvalidArgumentException("Landmark '{$id}' anchor requires one event marker, object id or legacy trigger id reference.");
            }
            $access = null;
            if (isset($anchor['event'])) {
                $event = $anchors->events[$anchor['event']] ?? throw new InvalidArgumentException("Landmark '{$id}' references a missing event.");
                if (isset($claimedEvents[$anchor['event']])) {
                    throw new InvalidArgumentException('An event can anchor only one landmark; use its disconnected placements for multiple markers.');
                }
                $claimedEvents[$anchor['event']] = true;
                $area = $event->area;
                if ($event->isTransfer) { $access = $event->accessConditions; }
            } elseif (isset($anchor['trigger'])) {
                $trigger = $anchors->triggers[$anchor['trigger']] ?? throw new InvalidArgumentException("Landmark '{$id}' references a missing legacy trigger id.");
                if (isset($claimedTriggers[$anchor['trigger']])) { throw new InvalidArgumentException('A legacy trigger can anchor only one landmark.'); }
                $claimedTriggers[$anchor['trigger']] = true;
                $area = $trigger->area;
                $access = $trigger->accessConditions;
            } else {
                $object = $anchors->objects[$anchor['object']] ?? throw new InvalidArgumentException("Landmark '{$id}' references a missing object.");
                $area = $object->area;
            }
            if ($access !== null && !$kind->isRoute()) {
                throw new InvalidArgumentException('A transfer landmark must use passage, entrance or exit so ordinary routes remain visible.');
            }
            if ($access === null && $kind->isRoute()) {
                throw new InvalidArgumentException('Route landmarks must reference a transfer event or legacy trigger.');
            }
            $secret = array_key_exists('secret', $entry) ? $entry['secret'] : false;
            $distance = array_key_exists('approachDistance', $entry) ? $entry['approachDistance'] : self::DEFAULT_APPROACH_DISTANCE;
            if (!is_bool($secret) || ($distance !== null && (!is_int($distance) || $distance < self::MIN_APPROACH_DISTANCE || $distance > self::MAX_APPROACH_DISTANCE))) {
                throw new InvalidArgumentException("Landmark '{$id}' needs a boolean secret and approachDistance null or 0..64 cells.");
            }
            if ($secret && array_key_exists('approachDistance', $entry)) {
                throw new InvalidArgumentException("Secret landmark '{$id}' cannot declare an approach distance; it requires explicit revelation.");
            }
            $conditions = $entry['revealConditions'] ?? [];
            if (!is_array($conditions) || !array_is_list($conditions) || count($conditions) > self::MAX_REVEAL_CONDITIONS
                || (array_key_exists('revealConditions', $entry) && $conditions === [])) {
                throw new InvalidArgumentException("Landmark '{$id}' revealConditions require 1..32 conditions when declared.");
            }
            WorldConditionEvaluator::validateAll($conditions, "landmarks/{$id}/revealConditions");
            if ($access !== null) { WorldConditionEvaluator::validateAll($access, "landmarks/{$id}/access"); }
            $name = array_key_exists('name', $entry) ? LocalizedText::fromArray($entry['name']) : null;
            $landmarks[] = new MapLandmarkDefinition($id, $kind, $area, $name, $secret, $distance, $conditions, $access);
            $ids[$id] = true;
        }
        // Old maps gain anonymous route markers without exposing destination names.
        // Explicit metadata replaces this automatic marker, including secret policy.
        foreach ($anchors->events as $marker => $event) {
            if (!$event->isTransfer || isset($claimedEvents[$marker])) { continue; }
            $landmarks[] = new MapLandmarkDefinition('@event:' . $marker, MapLandmarkKind::PASSAGE,
                $event->area, approachDistance: null, accessConditions: $event->accessConditions, automatic: true);
        }
        foreach ($anchors->triggers as $id => $trigger) {
            if (isset($claimedTriggers[$id])) { continue; }
            $landmarks[] = new MapLandmarkDefinition('@legacy:' . $id, MapLandmarkKind::PASSAGE, $trigger->area,
                approachDistance: null, accessConditions: $trigger->accessConditions, automatic: true);
        }
        return new self($landmarks);
    }

    /** Deterministic, map-scoped translation default; authors may choose another valid key. */
    public static function getNameMessageKey(string $mapId, string $landmarkId): string
    {
        if (trim($mapId) === '' || preg_match(self::ID_PATTERN, $landmarkId) !== 1) {
            throw new InvalidArgumentException('Landmark message keys require a map id and a valid landmark id.');
        }
        return 'landmarks.m' . hash('sha256', $mapId) . '.' . $landmarkId;
    }

    private static function assertAreaIsOnMap(CellArea $area, array $rowWidths): void
    {
        foreach ($area->cells as [$x, $y]) {
            if ($x < 0 || $y < 0 || !isset($rowWidths[$y]) || $x >= $rowWidths[$y]) {
                throw new InvalidArgumentException('Landmark anchor contains cells outside its map.');
            }
        }
    }
}
