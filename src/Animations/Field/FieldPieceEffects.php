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
    // Keep the canonical integer layers; string entry grids are an authoring export.
    $layers = [];
    foreach ($graphics->layers as $layer) { $layers[$layer->name] = $layer; }
    $effects = [];
    foreach ($graphics->tileset->pieces as $piece) {
      if ($piece->effect === null || $piece->tiles === []) { continue; }
      $stencil = [];
      foreach ($piece->tiles as $name => $rows) {
        foreach ($rows as $dy => $entries) {
          foreach ($entries as $dx => $tile) {
            if ($tile === '0') { continue; }
            $stencil[] = [$name, $dx, $dy, (int)$tile];
          }
        }
      }
      if ($stencil === []) { continue; }
      $first = array_key_first($piece->tiles);
      foreach ($layers[$first]->tiles ?? [] as $y => $row) {
        foreach ($row as $x => $_) {
          $matches = true;
          foreach ($stencil as [$name, $dx, $dy, $tile]) {
            if (($layers[$name]->tiles[$y + $dy][$x + $dx] ?? null) !== $tile) { $matches = false; break; }
          }
          if ($matches) {
            $effects[] = ['id' => 'piece-' . $piece->id . "-{$x}-{$y}", 'effect' => $piece->effect,
              'anchor' => ['cell' => ['x' => $x + intdiv($piece->width - 1, 2), 'y' => $y + $piece->height - 1]]];
          }
        }
      }
    }
    return $effects;
  }
}
