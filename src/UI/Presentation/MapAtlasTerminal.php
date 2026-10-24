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
use Ichiloto\Engine\IO\Console\TerminalText;

/** Shared physical atlas drawing. It reads knowledge supplied by gameplay. */
final class MapAtlasTerminal
{
    public static function getFocusHint(): string
    {
        $controls=self::findControls(['up','right','down','left']);
        $hint=TerminalControlText::formatControls($controls).' '.MessageCatalog::getTerm('map.focus');
        $pages=self::findControls(['menu_page_previous','menu_page_next']);
        return $hint.($pages===[] ? '' : TerminalControlText::SEPARATOR.TerminalControlText::formatControls($pages).' '.MessageCatalog::getTerm('map.cycle'));
    }

    /**
     * The distinct bound controls of a group of actions, in order.
     *
     * @param list<string> $actions
     * @return list<\Ichiloto\Engine\IO\ControlHint>
     */
    private static function findControls(array $actions): array
    {
        $controls=[];
        foreach ($actions as $action) {
            $control=ActionHints::resolveControl($action);
            if ($control!==null) { $controls[$control->family.'.'.$control->control]=$control; }
        }
        return array_values($controls);
    }

    public static function getMarkerSymbol(MapLandmarkKind $kind, bool $global = false, bool $places = false): string
    {
        return $global || !$kind->isRoute() || ($places && $kind===MapLandmarkKind::ENTRANCE) ? '■' : 'v';
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

    public static function getTerrainLegend(MapOverview $overview, bool $global, bool $places): string
    {
        $base=MessageCatalog::getTerm($global ? 'map.global_symbols' : 'map.terrain_markers');
        $roles=$overview->terrain?->getRoles() ?? [];
        if (!$places && !$global) { $roles[]='boundary'; }
        foreach (\Ichiloto\Engine\Field\MapAtlasTerrain::ROLES as $role) {
            if ($role!=='ground' && in_array($role,$roles,true)) { $base.='  '.MessageCatalog::getTerm('map.terrain.'.$role); }
        }
        return $base;
    }

    /**
     * @param list<\Ichiloto\Engine\Field\Waymarks\FieldWaymark> $waymarks Drawn over landmarks, under a selection and the player.
     * @return list<string> Stable whole-map rows; a selected landmark takes priority in a cluster.
     */
    public static function renderMap(MapOverview $overview, array $landmarks, Vector2 $player,
        MovementHeading $heading, int $width, int $height, int $selected = -1,
        ?MapProjection $projection = null, bool $global = false, bool $drawOutlines = true,
        array $waymarks = [], ?\Ichiloto\Engine\Animations\Field\FieldWaymarkStyle $waymarkStyle = null,
        ?\Ichiloto\Engine\Animations\Field\FieldCueStyle $cueStyle = null): array
    {
        $projection ??= $overview->getTerminalProjection($width, $height);
        $raster = $overview->getTerminalRaster($width, $height, $projection,$drawOutlines);
        if ($overview->terrain!==null) {
            $colors=[':'=>Color::BROWN,'='=>Color::YELLOW,'~'=>Color::LIGHT_BLUE,
                '*'=>Color::GREEN,'^'=>Color::LIGHT_GRAY,'x'=>Color::DARK_GRAY,'#'=>Color::LIGHT_GRAY];
            $styled=[];
            foreach ($colors as $symbol=>$color) { $styled[$symbol]=Color::apply($symbol,$color); }
            foreach ($raster as &$row) {
                $row=array_map(static fn(string $symbol): string=>$styled[$symbol] ?? $symbol,$row);
            }
            unset($row);
        }
        $occupied = [];
        foreach ($landmarks as $index => $landmark) {
            foreach ($landmark['definition']->getMarkerCells() as $cell) {
                if (!$projection->containsCell((int)$cell->x,(int)$cell->y)) { continue; }
                $point = $projection->projectCell((int) $cell->x, (int) $cell->y);
                $x = min($width - 1, (int) floor($point->x));
                $y = min($height - 1, (int) floor($point->y));
                $key = "{$x}:{$y}";
                $symbol = self::getMarkerSymbol($landmark['definition']->kind,$global,!$drawOutlines);
                if ($index === $selected) {
                    $raster[$y][$x] = SelectionStyle::apply($symbol);
                    $occupied[$key] = true;
                } elseif (($occupied[$key] ?? false) !== true) {
                    $raster[$y][$x] = SelectionStyle::applyMarked($symbol);
                    $occupied[$key] = false;
                }
            }
        }
        $waymarkStyle ??= new \Ichiloto\Engine\Animations\Field\FieldWaymarkStyle();
        foreach ($waymarks as $waymark) {
            $cell = $waymark->anchor->cell;
            if (!$projection->containsCell((int)$cell->x,(int)$cell->y)) { continue; }
            $point = $projection->projectCell((int)$cell->x, (int)$cell->y);
            $x = min($width - 1, (int) floor($point->x));
            $y = min($height - 1, (int) floor($point->y));
            if (($occupied["{$x}:{$y}"] ?? false) === true) { continue; }
            $raster[$y][$x] = TerminalText::formatStyles($waymarkStyle->styleSymbol($cueStyle ?? new \Ichiloto\Engine\Animations\Field\FieldCueStyle()));
            $occupied["{$x}:{$y}"] = false;
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
