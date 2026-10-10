<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldViewport;

/** A ray from the viewport centre meets its inset edge, independent of map collision. */
final class FieldCueEdgePlacement
{
  /** @param array{x: int, y: int} $origin @return array{direction: string, position: Vector2}|null */
  public static function locate(Vector2 $cell, array $origin, FieldViewport $view): ?array
  {
    $x = $cell->x - $origin['x'];
    $y = $cell->y - $origin['y'];
    if ($x >= 0 && $x < $view->columns && $y >= 0 && $y < $view->rows) { return null; }
    $dx = $x - ($view->columns - 1) / 2;
    $dy = $y - ($view->rows - 1) / 2;
    $direction = (int)round(atan2($dy, $dx) / (M_PI / 4));
    $direction = ($direction + 8) % 8;
    $halfWidth = max(0, ($view->columns - 1) / 2);
    $halfHeight = max(0, ($view->rows - 1) / 2);
    $scale = min($dx == 0 ? INF : $halfWidth / abs($dx), $dy == 0 ? INF : $halfHeight / abs($dy));
    $tile = FieldViewport::TILE_SIZE * $view->zoom;
    $surfaceWidth = $view->grid->columns * $view->grid->cellWidth;
    $surfaceHeight = $view->grid->rows * $view->grid->cellHeight;
    $pixelX = $surfaceWidth / 2 + $dx * $scale * $tile;
    $pixelY = $surfaceHeight / 2 + $dy * $scale * $tile;
    return ['direction' => FieldPresentationCatalog::DIRECTIONS[$direction],
      'position' => new Vector2((int)round($pixelX / $view->grid->cellWidth - 0.5),
        (int)round(($pixelY + $tile / 2) / $view->grid->cellHeight - 1))];
  }
}
