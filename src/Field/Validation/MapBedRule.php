<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\NpcBedDefinition;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use InvalidArgumentException;

/** Bed slots and standing exits read the shared physical grid, independently of images. */
final class MapBedRule
{
    public const string RULE = 'npc.bed';

    /** @param list<WorldObjectDefinition> $objects @return list<MapValidationIssue> */
    public static function inspect(string $mapId, array $data, array $objects, array $collisions): array
    {
        $issues = $claims = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $index => $entry) {
            if (!is_array($entry) || !array_key_exists('bed', $entry)) { continue; }
            try {
                if (!is_string($entry['id'] ?? null) || trim($entry['id']) === ''
                    || !is_int($entry['x'] ?? null) || !is_int($entry['y'] ?? null)) {
                    throw new InvalidArgumentException('A bed occupant needs a stable NPC id and an integer standing-exit x/y.');
                }
                $bed = NpcBedDefinition::fromArray($entry['bed']);
                [$object, $attachment] = $bed->resolveObject($objects);
                $x = $object->x + $attachment->cellX; $y = $object->y + $attachment->cellY;
                $read = static fn(int $x, int $y): ?CollisionType => isset($collisions[$y][$x])
                    ? ($collisions[$y][$x] instanceof CollisionType ? $collisions[$y][$x] : CollisionType::tryFrom($collisions[$y][$x])) : null;
                if ($read($x, $y) === null || !$read($x, $y)->blocksMovement()) {
                    throw new InvalidArgumentException('The bed attachment cell must be an authored solid physical bed cell.');
                }
                $exit = $read($entry['x'], $entry['y']);
                if ($exit === null || $exit->blocksMovement()) {
                    throw new InvalidArgumentException('The bed standing exit must be clear physical floor.');
                }
                $conditions = is_array($entry['conditions'] ?? null) ? $entry['conditions'] : [];
                $key = "$x:$y";
                foreach ($claims[$key] ?? [] as $other) {
                    if (!WorldConditionExclusion::proveExclusive($conditions, $other)) {
                        throw new InvalidArgumentException('Bed occupants sharing a slot need mutually exclusive presence conditions.');
                    }
                }
                $claims[$key][] = $conditions;
            } catch (InvalidArgumentException $error) {
                $issues[] = new MapValidationIssue($mapId, self::RULE, $error->getMessage(), path: ['npcs', $index, 'bed'],
                    hint: 'Select a bed object and its occupant attachment, then author the physical bed and a clear standing exit.');
            }
        }
        return $issues;
    }
}
