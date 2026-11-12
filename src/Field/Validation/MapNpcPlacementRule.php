<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\NpcPlacementSet;
use InvalidArgumentException;

/** All authored persistent destinations must remain physical floor, independent of artwork. */
final class MapNpcPlacementRule
{
    public const string RULE = 'npc.placement';

    /** @return list<MapValidationIssue> */
    public static function inspect(string $mapId, array $data, array $collisions): array
    {
        $issues = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $index => $entry) {
            if (!is_array($entry) || !array_key_exists('placements', $entry)) { continue; }
            try {
                foreach (NpcPlacementSet::fromEntry($entry)->placements as $id => $placement) {
                    $cell = $collisions[$placement['y']][$placement['x']] ?? null;
                    $collision = $cell instanceof CollisionType ? $cell : (is_int($cell) ? CollisionType::tryFrom($cell) : null);
                    if ($collision === null || $collision->blocksMovement()) {
                        throw new InvalidArgumentException("NPC placement {$id} must be current clear physical floor.");
                    }
                }
            } catch (InvalidArgumentException $error) {
                $issues[] = new MapValidationIssue($mapId, self::RULE, $error->getMessage(), path: ['npcs', $index, 'placements'],
                    hint: 'Choose stable placement names with current clear floor cells and cardinal headings.');
            }
        }
        return $issues;
    }
}
