<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapLandmarkKind;
use Ichiloto\Engine\Field\MapOverview;
use Ichiloto\Engine\Field\MapProjection;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\ActionHints;

/** Shared physical atlas drawing. It reads knowledge supplied by gameplay. */
final class MapAtlasTerminal
{
    public static function getFocusHint(): string
    {
        $controls=[];
        foreach (['up','right','down','left'] as $action) {
            $hint=ActionHints::resolve($action,MessageCatalog::getTerm('map.focus'));
            if ($hint->control!==null) { $controls[]=$hint->control->label; }
        }
        $hint=($controls===[] ? 'Unbound' : implode('/',array_unique($controls))).':'.MessageCatalog::getTerm('map.focus');
        $pages=[];
        foreach (['menu_page_previous','menu_page_next'] as $action) {
            $control=ActionHints::resolve($action,MessageCatalog::getTerm('map.cycle'))->control;
            if ($control!==null) { $pages[]=$control->label; }
        }
        return $hint.($pages===[] ? '' : '  '.implode('/',array_unique($pages)).':'.MessageCatalog::getTerm('map.cycle'));
    }

    public static function getMarkerSymbol(MapLandmarkKind $kind, bool $global = false): string
    {
        return $global ? '■' : ($kind->isRoute() ? 'v' : '*');
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
        MovementHeading $heading, int $width, int $height, int $selected = -1,
        ?MapProjection $projection = null, bool $global = false): array
    {
        $projection ??= $overview->getTerminalProjection($width, $height);
        $raster = $overview->getTerminalRaster($width, $height, $projection);
        $occupied = [];
        foreach ($landmarks as $index => $landmark) {
            foreach ($landmark['definition']->getMarkerCells() as $cell) {
                if (!$projection->containsCell((int)$cell->x,(int)$cell->y)) { continue; }
                $point = $projection->projectCell((int) $cell->x, (int) $cell->y);
                $x = min($width - 1, (int) floor($point->x));
                $y = min($height - 1, (int) floor($point->y));
                $key = "{$x}:{$y}";
                $symbol = self::getMarkerSymbol($landmark['definition']->kind,$global);
                if ($index === $selected) {
                    $raster[$y][$x] = SelectionStyle::apply($symbol);
                    $occupied[$key] = true;
                } elseif (($occupied[$key] ?? false) !== true) {
                    $raster[$y][$x] = SelectionStyle::applyMarked($symbol);
                    $occupied[$key] = false;
                }
            }
        }
        $playerX = (int) floor($player->x);
        $playerY = (int) floor($player->y);
        if ($playerX >= 0 && $playerY >= 0 && $playerX < $overview->width && $playerY < $overview->height
            && $projection->containsCell($playerX,$playerY)) {
            $point = $projection->projectCell($playerX, $playerY);
            $x=min($width - 1, (int) floor($point->x));
            $y=min($height - 1, (int) floor($point->y));
            $symbol='@';
            $raster[$y][$x]=($occupied["{$x}:{$y}"] ?? false)===true ? SelectionStyle::apply($symbol)
                : Color::apply($symbol,Color::LIGHT_CYAN);
        }
        return array_map(static fn(array $row) => implode('', $row), $raster);
    }
}
