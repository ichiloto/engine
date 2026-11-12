<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Field\NpcPlacementSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use InvalidArgumentException;
use RuntimeException;

/** Every declared resting role is checked independently of physical placement and current save selection. */
final class MapNpcPlacementArtRule
{
    public const string RULE = 'npc.placement.art';

    /** @return list<MapValidationIssue> */
    public static function inspect(string $mapId, array $data, string $assetRoot): array
    {
        $issues = [];
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $index => $npc) {
            if (!is_array($npc) || !array_key_exists('placements', $npc)) { continue; }
            try { $placements = NpcPlacementSet::fromEntry($npc); }
            catch (InvalidArgumentException) { continue; } // Physical validation owns malformed destinations.
            foreach ($placements->poses as $id => $poseData) {
                if ($poseData === null) { continue; }
                $role = null;
                try {
                    $definition = $placements->getPose($id);
                    $role = $definition === null ? null : new FieldSpriteRole($definition, $assetRoot,
                        'Map validation NPC placement ' . $id);
                    if ($role?->getStillFrame(new PresentationSpritePivot()) === null) {
                        throw new InvalidArgumentException('NPC placement resting art is unavailable.');
                    }
                } catch (InvalidArgumentException | RuntimeException $error) {
                    $issues[] = new MapValidationIssue($mapId, self::RULE, $error->getMessage(),
                        path: ['npcs', $index, 'placements', $id, 'sprites2d'],
                        hint: 'Choose a current field image, character sheet or pose animation through the asset picker.');
                } finally { $role?->release(); }
            }
        }
        return $issues;
    }
}
