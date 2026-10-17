<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapLandmarkKind;
use Ichiloto\Engine\Field\MapOverview;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\UI\SelectionStyle;

/** Shared physical atlas drawing. It reads knowledge supplied by gameplay. */
final class MapAtlasTerminal
{
    public static function getMarkerSymbol(MapLandmarkKind $kind): string
    {
        return $kind->isRoute() ? 'E' : ($kind === MapLandmarkKind::BUILDING ? 'B' : '*');
    }

    public static function getLandmarkLabel(array $landmark): string
    {
        return $landmark['name'] ?? match ($landmark['definition']->kind) {
            MapLandmarkKind::ENTRANCE => MessageCatalog::getTerm('map.entrance'),
            MapLandmarkKind::EXIT => MessageCatalog::getTerm('map.exit'),
            MapLandmarkKind::PASSAGE => MessageCatalog::getTerm('map.passage'),
            MapLandmarkKind::BUILDING => MessageCatalog::getTerm('map.building'),
            MapLandmarkKind::LANDMARK => MessageCatalog::getTerm('map.landmark'),
        };
    }

    /** @return list<string> Stable whole-map rows; a selected landmark takes priority in a cluster. */
    public static function renderMap(MapOverview $overview, array $landmarks, Vector2 $player,
        MovementHeading $heading, int $width, int $height, int $selected = -1): array
    {
        $raster = $overview->getTerminalRaster($width, $height);
        $projection = $overview->getTerminalProjection($width, $height);
        $occupied = [];
        foreach ($landmarks as $index => $landmark) {
            foreach ($landmark['definition']->getMarkerCells() as $cell) {
                $point = $projection->projectCell((int) $cell->x, (int) $cell->y);
                $x = min($width - 1, (int) floor($point->x));
                $y = min($height - 1, (int) floor($point->y));
                $key = "{$x}:{$y}";
                $symbol = self::getMarkerSymbol($landmark['definition']->kind);
                if ($index === $selected) {
                    $raster[$y][$x] = SelectionStyle::apply($symbol);
                    $occupied[$key] = true;
                } elseif (($occupied[$key] ?? false) !== true) {
                    $raster[$y][$x] = isset($occupied[$key]) ? '+' : $symbol;
                    $occupied[$key] = false;
                }
            }
        }
        $playerX = (int) floor($player->x);
        $playerY = (int) floor($player->y);
        if ($playerX >= 0 && $playerY >= 0 && $playerX < $overview->width && $playerY < $overview->height) {
            $point = $projection->projectCell($playerX, $playerY);
            $raster[min($height - 1, (int) floor($point->y))][min($width - 1, (int) floor($point->x))] = '<fg=bright-cyan>' . match ($heading) {
                MovementHeading::NORTH => '^', MovementHeading::EAST => '>',
                MovementHeading::SOUTH => 'v', MovementHeading::WEST => '<', default => '@',
            } . '</>';
        }
        return array_map(static fn(array $row) => implode('', $row), $raster);
    }
}
