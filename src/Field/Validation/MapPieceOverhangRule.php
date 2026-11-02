<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;

/** Visual piece rows below characters need movement-blocking backing on the authored physical map. */
final class MapPieceOverhangRule
{
    public const string RULE = 'piece.overhang';

    private function __construct()
    {
    }

    /** @return list<MapValidationIssue> */
    public static function inspect(string $mapId, MapGraphics $graphics, ?MapPhysicalOccupancy $occupancy = null): array
    {
        $issues = [];
        foreach ($graphics->tileset->pieces as $piece) {
            if ($piece->overhang === 0) { continue; }
            foreach ($graphics->findPieceStamps($piece) as [$left, $top]) {
                $off = [];
                for ($dy = 0; $dy < $piece->overhang; $dy++) {
                    for ($dx = 0; $dx < $piece->width; $dx++) {
                        $needsBacking = array_any($piece->tiles, static function (array $rows) use ($dy, $dx, $graphics): bool {
                            $tile = (int) $rows[$dy][$dx];
                            return $tile !== 0 && !$graphics->tileset->isAbove($tile);
                        });
                        if (!$needsBacking) { continue; }
                        [$x, $y] = [$left + $dx, $top + $dy];
                        $type = $occupancy?->collisionGrid[$y][$x] ?? null;
                        if ($type === null || !CollisionType::from($type)->blocksMovement()) { $off[] = [$x, $y]; }
                    }
                }
                if ($off === []) { continue; }
                if ($occupancy === null) {
                    $issues[] = new MapValidationIssue($mapId, self::RULE,
                        "{$piece->name} needs resolved physical occupancy to check its overhang backing.", cells: $off,
                        path: [MapPhysicalOccupancy::DATA_KEY],
                        hint: 'Supply the map’s resolved physical occupancy, including legacy maps, to completeness validation.');
                    continue;
                }
                [$x, $y] = $off[0];
                $issues[] = new MapValidationIssue($mapId, self::RULE,
                    sprintf('%s at (%d, %d) rises over unblocked floor at (%d, %d); stand it with its back to a wall, or draw its top above characters.',
                        $piece->name, $left, $top + $piece->overhang, $x, $y), cells: $off,
                    hint: 'Keep the physical footprint on the ground rows and place the overhang against movement-blocking backing, or mark its tiles above characters.');
            }
        }
        return $issues;
    }
}
