<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\PlaceTypeCatalog;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use InvalidArgumentException;

/**
 * On a map whose tileset gives place types their icons
 * ({@see \Ichiloto\Engine\Rendering\Tilesets\Tileset::$placeIcons}), such as a
 * world map, every entrance into a place shows that place's type: the piece
 * its tileset names for the type stands whole over the entrance, in its tiles
 * ({@see MapGraphics::findPieceStampsCovering()}) and in its terminal glyphs
 * alike, so a single building never shows as a walled town in either
 * presentation. Reported:
 *
 * - `place.icon`: an entrance into a place of a type that shows another
 *   piece, or none, over it, or a type the tileset gives no icon;
 * - `place.untyped`: a place icon over an entrance into a map that names no
 *   place type, so what it claims cannot be checked.
 *
 * An entrance is a transfer event; one into a map naming no type under no
 * place icon is a plain way through, and is not judged. Destination types the
 * project does not have, and doors to maps that do not exist, are reported by
 * the checks that own them. Findings are warnings: the map plays, saves and
 * loads unchanged.
 */
final class MapPlaceIconRule
{
    public const string ICON = 'place.icon';
    public const string UNTYPED = 'place.untyped';

    private function __construct()
    {
    }

    /**
     * @param list<array> $events Resolved event definitions ({@see MapEventResolver}).
     * @param array<string, ?string> $placeTypes Every readable map's place type, null when it names none, by map id.
     * @return list<MapValidationIssue>
     */
    public static function inspect(string $mapId, array $events, MapLayerSet $layers, ?MapGraphics $graphics,
        PlaceTypeCatalog $types, array $placeTypes): array
    {
        $tileset = $graphics?->tileset;
        if ($tileset === null || $tileset->placeIcons === []) { return []; }
        $icons = [];
        foreach (array_unique($tileset->placeIcons) as $pieceId) { $icons[$pieceId] = $tileset->pieces[$pieceId]; }
        $issues = [];
        foreach ($events as $event) {
            $class = $event['class'] ?? null;
            $destination = $event['data']['destinationMap'] ?? null;
            if (!is_string($class) || !is_a($class, TransferPlayerTrigger::class, true) || !is_string($destination)
                || !array_key_exists($destination, $placeTypes)) {
                continue;
            }
            try {
                $area = CellArea::fromArray($event['area'] ?? null, 'Event area');
            } catch (InvalidArgumentException) {
                continue; // event.definition reports it.
            }
            $marker = is_string($event['marker'] ?? null) ? $event['marker'] : null;
            $label = $marker === null ? 'An entrance' : "Entrance '{$marker}'";
            $report = static fn(string $rule, string $message, string $hint): MapValidationIssue => new MapValidationIssue(
                $mapId, $rule, $message, $marker, $area->cells, ['events', $marker ?? ''], $hint);
            $standing = self::findIconOver($graphics, $layers, $icons, $area);
            $type = $placeTypes[$destination];
            if ($type === null) {
                if ($standing !== null) {
                    $issues[] = $report(self::UNTYPED, sprintf('%s shows the %s icon but leads to %s, which names no place type.',
                        $label, $standing->name, $destination),
                        "Give {$destination} its place type, so the icon over this entrance shows what kind of place it is.");
                }
                continue;
            }
            if (!$types->hasType($type)) { continue; }
            $name = $types->getName($type);
            $expected = $tileset->getPlaceIcon($type);
            if ($expected === null) {
                $issues[] = $report(self::ICON, sprintf('%s leads to %s, a place of type %s, but tileset %s shows no icon for %s.',
                    $label, $destination, $name, $tileset->name, $name),
                    "Choose the piece tileset {$tileset->name} shows for {$name} in its place icons.");
                continue;
            }
            if ($standing === $expected) { continue; }
            $drawn = $standing === null && self::findStampsOver($graphics, $expected, $area) !== [];
            $issues[] = $report(self::ICON, sprintf('%s leads to %s, a place of type %s, whose icon is %s; %s.', $label, $destination,
                $name, $expected->name, match (true) {
                    $standing !== null => "the {$standing->name} stands over it",
                    $drawn => "its tiles show {$expected->name} but its terminal glyphs do not",
                    default => 'no place icon stands over it',
                }),
                "Stamp {$expected->name} over the entrance in place of what stands there, keeping the entrance cell, "
                . "or change the place type of {$destination}.");
        }
        return $issues;
    }

    /**
     * The place icon standing whole over a cell of the area, in its tiles and
     * its terminal glyphs; the smallest when several do.
     *
     * @param array<string, TilesetPiece> $icons
     */
    private static function findIconOver(MapGraphics $graphics, MapLayerSet $layers, array $icons, CellArea $area): ?TilesetPiece
    {
        $found = null;
        foreach ($icons as $piece) {
            foreach (self::findStampsOver($graphics, $piece, $area) as [$left, $top]) {
                if (!self::showsGlyphs($layers, $piece, $left, $top)) { continue; }
                if ($found === null || $piece->width * $piece->height < $found->width * $found->height) { $found = $piece; }
            }
        }
        return $found;
    }

    /** @return list<array{0: int, 1: int}> The top-left cells of the piece's copies covering any cell of the area. */
    private static function findStampsOver(MapGraphics $graphics, TilesetPiece $piece, CellArea $area): array
    {
        $stamps = [];
        foreach ($area->cells as [$x, $y]) {
            foreach ($graphics->findPieceStampsCovering($piece, $x, $y) as $origin) { $stamps[implode(',', $origin)] = $origin; }
        }
        return array_values($stamps);
    }

    /** Whether the piece's glyph layer shows each of its glyphs at a copy, so the terminal tells what the tiles do. */
    private static function showsGlyphs(MapLayerSet $layers, TilesetPiece $piece, int $left, int $top): bool
    {
        $layer = null;
        foreach ($layers->layers as $candidate) {
            if ($candidate->name === $piece->layer) { $layer = $candidate; }
        }
        foreach ($piece->glyphs as $dy => $row) {
            foreach ($row as $dx => $glyph) {
                if ($glyph === ' ') { continue; }
                if ($layer?->getGlyphAt($left + $dx, $top + $dy) !== $glyph) { return false; }
            }
        }
        return true;
    }
}
