<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * On a map painted in terrain kinds, every cell's glyph and tiles show the
 * same kind ({@see MapTerrainKinds::findKindAt()}). Painting a kind writes
 * both, so they disagree only after an edit made them part company: a glyph
 * typed over a kind's tiles, a tile painted under a kind's glyph, or an old
 * glyph a conversion did not map. Reported:
 *
 * - `terrain.disagreement`: a kind's glyph whose tiles are not that kind's,
 *   or a kind's autotile under a glyph that names no kind and that no
 *   authored piece pairs with that tile;
 * - `terrain.awaiting-art`: cells painted with a kind whose autotile is still
 *   to be drawn, so the graphical field shows their glyph.
 *
 * Passage needs no check here: it comes from the kind, and a kind's autotile
 * shows what its passage says ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetAppearance::withKinds()}).
 * Findings are warnings; the map plays, saves and loads unchanged.
 */
final class MapTerrainKindRule
{
    public const string DISAGREEMENT = 'terrain.disagreement';
    public const string AWAITING_ART = 'terrain.awaiting-art';

    private function __construct()
    {
    }

    /** @return list<MapValidationIssue> */
    public static function inspect(string $mapId, MapLayerSet $layers, MapGraphics $graphics, MapTerrainKinds $kinds): array
    {
        $tiles = [];
        foreach ($graphics->layers as $layer) {
            $tiles[$layer->name] = $layer;
        }
        $paired = self::findPieceTiles($graphics->tileset->pieces);
        $disagreements = $awaiting = [];
        foreach ($layers->layers as $layer) {
            if ($layer->decoration || !in_array($layer->name, $kinds->getLayers(), true)) {
                continue;
            }
            for ($y = 0; $y < $layer->height; $y++) {
                foreach ($layer->getRowGlyphs($y) as $x => $glyph) {
                    $tileAt = static fn(string $name): int => ($tiles[$name] ?? null)?->getTileAt($x, $y) ?? TileId::EMPTY;
                    $named = $kinds->getKindsOn($layer->name, $glyph);
                    if ($named !== []) {
                        $kind = $kinds->findKindAt($layer->name, $glyph, $tileAt);
                        if ($kind === null) {
                            $disagreements[$named[0]->name][] = [$x, $y];
                        } elseif ($kind->awaitingArt) {
                            $awaiting[$kind->name][] = [$x, $y];
                        }
                        continue;
                    }
                    $shown = $kinds->findKindByTile($tileAt);
                    if ($shown !== null && !isset($paired[$layer->name][$glyph][$shown->art['layer']][$shown->art['tile']])) {
                        $disagreements[$shown->name][] = [$x, $y];
                    }
                }
            }
        }
        $issues = [];
        foreach ($disagreements as $name => $cells) {
            $issues[] = new MapValidationIssue($mapId, self::DISAGREEMENT,
                "The terminal glyph and the tiles of these cells do not show the same terrain kind ({$name}).", cells: $cells,
                hint: 'Paint the cells with one terrain kind, which writes its glyph and its tiles together.');
        }
        foreach ($awaiting as $name => $cells) {
            $issues[] = new MapValidationIssue($mapId, self::AWAITING_ART,
                "{$name} is awaiting its art, so the graphical field shows its glyph in these cells.", cells: $cells,
                hint: "Bind the kind's autotile in its tileset once the art is drawn.");
        }
        return $issues;
    }

    /**
     * The tiles authored pieces pair with a glyph, by gameplay layer, glyph,
     * tile layer and flag identity: a kind's autotile drawn by such a piece is
     * that piece's, not a kind out of place.
     *
     * @param array<string, TilesetPiece> $pieces
     * @return array<string, array<string, array<string, array<int, true>>>>
     */
    private static function findPieceTiles(array $pieces): array
    {
        $paired = [];
        foreach ($pieces as $piece) {
            if ($piece->connects !== null) {
                foreach ($piece->shapes as $shape => $glyph) {
                    foreach ($piece->shapeTiles as $tileLayer => $entries) {
                        $paired[$piece->layer][$glyph][$tileLayer][TileId::getFlagId((int)$entries[$shape])] = true;
                    }
                }
                continue;
            }
            foreach ($piece->glyphs as $row => $symbols) {
                foreach ($symbols as $column => $glyph) {
                    foreach ($piece->tiles as $tileLayer => $entries) {
                        $paired[$piece->layer][$glyph][$tileLayer][TileId::getFlagId((int)$entries[$row][$column])] = true;
                    }
                }
            }
        }
        return $paired;
    }
}
