<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Field\MapGraphics;

/** Recognize stamped graphical pieces, never terminal glyphs or collision. */
final class FieldPieceEffects
{
  /** @return list<array{id: string, effect: string, anchor: array{cell: array{x: int, y: int}}}> */
  public static function find(MapGraphics $graphics): array
  {
    $grids = [];
    foreach ($graphics->layers as $layer) { $grids[$layer->name] = $layer->getEntries(); }
    $effects = [];
    foreach ($graphics->tileset->pieces as $piece) {
      if ($piece->effect === null || $piece->tiles === []) { continue; }
      $first = array_key_first($piece->tiles);
      $grid = $grids[$first] ?? [];
      foreach ($grid as $y => $row) {
        foreach ($row as $x => $_) {
          $matches = true;
          $hasTile = false;
          foreach ($piece->tiles as $name => $rows) {
            foreach ($rows as $dy => $entries) {
              foreach ($entries as $dx => $tile) {
                if ($tile === '0') { continue; }
                $hasTile = true;
                if (($grids[$name][$y + $dy][$x + $dx] ?? null) !== $tile) { $matches = false; break 3; }
              }
            }
          }
          if ($matches && $hasTile) {
            $effects[] = ['id' => 'piece-' . $piece->id . "-{$x}-{$y}", 'effect' => $piece->effect,
              'anchor' => ['cell' => ['x' => $x + intdiv($piece->width - 1, 2), 'y' => $y + $piece->height - 1]]];
          }
        }
      }
    }
    return $effects;
  }
}
