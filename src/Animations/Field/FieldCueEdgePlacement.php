<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;

/**
 * A ray from the viewport centre meets its inset edge, independent of map
 * collision and of every field sprite: guidance is UI, so it never moves to
 * make way for the player or anyone else.
 */
final class FieldCueEdgePlacement
{
  /**
   * Where an edge image goes: its compass direction and its grid cell.
   *
   * @param array{x: int, y: int} $origin
   * @param int $inset Cells kept between the edge image and the view edge.
   * @return array{direction: string, position: Vector2}|null Null while the cell is on screen.
   */
  public static function locate(Vector2 $cell, array $origin, FieldViewport $view, int $inset = 0): ?array
  {
    $edge = self::locatePixels($cell, $origin, $view, $inset);
    if ($edge === null) { return null; }
    ['position' => $position] = self::findCell($edge['x'], $edge['y'], $view);

    return ['direction' => $edge['direction'], 'position' => $position];
  }

  /**
   * Where an edge image's centre goes on the surface, in logical pixels, and
   * the unit vector pointing out of the view in its compass direction.
   *
   * @param array{x: int, y: int} $origin
   * @return array{direction: string, x: float, y: float, outward: array{x: float, y: float}}|null Null while on screen.
   */
  public static function locatePixels(Vector2 $cell, array $origin, FieldViewport $view, int $inset = 0): ?array
  {
    $x = $cell->x - $origin['x'];
    $y = $cell->y - $origin['y'];
    if ($x >= 0 && $x < $view->columns && $y >= 0 && $y < $view->rows) { return null; }
    $dx = $x - ($view->columns - 1) / 2;
    $dy = $y - ($view->rows - 1) / 2;
    $index = ((int)round(atan2($dy, $dx) / (M_PI / 4)) + 8) % 8;
    $halfWidth = max(0, ($view->columns - 1) / 2 - $inset);
    $halfHeight = max(0, ($view->rows - 1) / 2 - $inset);
    $scale = min($dx == 0 ? INF : $halfWidth / abs($dx), $dy == 0 ? INF : $halfHeight / abs($dy));
    $tile = FieldViewport::TILE_SIZE * $view->zoom;
    $surfaceWidth = $view->grid->columns * $view->grid->cellWidth;
    $surfaceHeight = $view->grid->rows * $view->grid->cellHeight;
    $pixelX = $surfaceWidth / 2 + $dx * $scale * $tile;
    $pixelY = $surfaceHeight / 2 + $dy * $scale * $tile;
    $angle = $index * M_PI / 4;

    return ['direction' => FieldPresentationCatalog::DIRECTIONS[$index], 'x' => $pixelX, 'y' => $pixelY,
      'outward' => ['x' => round(cos($angle), 6), 'y' => round(sin($angle), 6)]];
  }

  /**
   * The grid cell, lift and pivot that put the centre of a bottom-centred
   * image of the given width at a surface pixel: the row and lift exactly,
   * and the column's remainder through the pivot.
   *
   * @return array{position: Vector2, lift: int, pivot: PresentationSpritePivot}
   */
  public static function findCell(float $pixelX, float $pixelY, FieldViewport $view, ?int $width = null): array
  {
    $width ??= (int)round(FieldViewport::TILE_SIZE * $view->zoom);
    $bottom = $pixelY + FieldViewport::TILE_SIZE * $view->zoom / 2;
    $row = (int)ceil($bottom / $view->grid->cellHeight) - 1;
    $column = (int)round($pixelX / $view->grid->cellWidth - 0.5);
    $remainder = $pixelX - ($column + 0.5) * $view->grid->cellWidth;

    return ['position' => new Vector2($column, $row),
      'lift' => (int)round(($row + 1) * $view->grid->cellHeight - $bottom),
      'pivot' => new PresentationSpritePivot(max(0.0, min(1.0, 0.5 - $remainder / max(1, $width))), 1.0)];
  }
}
