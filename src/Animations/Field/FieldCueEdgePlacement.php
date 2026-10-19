<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldViewport;

/** A ray from the viewport centre meets its inset edge, independent of map collision. */
final class FieldCueEdgePlacement
{
  /**
   * @param array{x: int, y: int} $origin
   * @param int $inset Cells kept between the edge image and the view edge.
   * @param Vector2|null $player The player's world cell; the image slides along the edge off it.
   * @return array{direction: string, position: Vector2}|null Null while the cell is on screen.
   */
  public static function locate(Vector2 $cell, array $origin, FieldViewport $view, int $inset = 0, ?Vector2 $player = null): ?array
  {
    $x = $cell->x - $origin['x'];
    $y = $cell->y - $origin['y'];
    if ($x >= 0 && $x < $view->columns && $y >= 0 && $y < $view->rows) { return null; }
    $dx = $x - ($view->columns - 1) / 2;
    $dy = $y - ($view->rows - 1) / 2;
    $direction = (int)round(atan2($dy, $dx) / (M_PI / 4));
    $direction = ($direction + 8) % 8;
    $halfWidth = max(0, ($view->columns - 1) / 2 - $inset);
    $halfHeight = max(0, ($view->rows - 1) / 2 - $inset);
    $scale = min($dx == 0 ? INF : $halfWidth / abs($dx), $dy == 0 ? INF : $halfHeight / abs($dy));
    $tile = FieldViewport::TILE_SIZE * $view->zoom;
    $surfaceWidth = $view->grid->columns * $view->grid->cellWidth;
    $surfaceHeight = $view->grid->rows * $view->grid->cellHeight;
    $pixelX = $surfaceWidth / 2 + $dx * $scale * $tile;
    $pixelY = $surfaceHeight / 2 + $dy * $scale * $tile;
    if ($player !== null) {
      $playerX = $surfaceWidth / 2 + ($player->x - $origin['x'] - ($view->columns - 1) / 2) * $tile;
      $playerY = $surfaceHeight / 2 + ($player->y - $origin['y'] - ($view->rows - 1) / 2) * $tile;
      if (abs($pixelX - $playerX) < $tile && abs($pixelY - $playerY) < $tile) {
        // Slide one tile along the edge the image sits on, toward the view's middle.
        if (abs($dx) * $halfHeight >= abs($dy) * $halfWidth) {
          $pixelY += ($pixelY > $playerY || ($pixelY == $playerY && $pixelY < $surfaceHeight / 2) ? 1 : -1) * $tile;
        } else {
          $pixelX += ($pixelX > $playerX || ($pixelX == $playerX && $pixelX < $surfaceWidth / 2) ? 1 : -1) * $tile;
        }
      }
    }
    return ['direction' => FieldPresentationCatalog::DIRECTIONS[$direction],
      'position' => new Vector2((int)round($pixelX / $view->grid->cellWidth - 0.5),
        (int)round(($pixelY + $tile / 2) / $view->grid->cellHeight - 1))];
  }
}
