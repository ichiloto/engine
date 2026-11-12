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
    $effects = [];
    foreach ($graphics->tileset->pieces as $piece) {
      if ($piece->effect === null || $piece->tiles === []) { continue; }
      foreach ($graphics->findPieceStamps($piece) as [$x, $y]) {
        $effects[] = ['id' => 'piece-' . $piece->id . "-{$x}-{$y}", 'effect' => $piece->effect,
          'anchor' => ['cell' => ['x' => $x + intdiv($piece->width - 1, 2), 'y' => $y + $piece->height - 1]]];
      }
    }
    return $effects;
  }
}
