<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/** Conservative protection of real UI and actor footprints, independent of any game or label text. */
final class NotificationPlacement
{
  public static function isClear(CanvasRectangle $box, array $protected): bool
  {
    foreach ($protected as $area) {
      if ($box->x < $area->x + $area->width && $box->x + $box->width > $area->x
        && $box->y < $area->y + $area->height && $box->y + $box->height > $area->y) { return false; }
    }
    return true;
  }

  public static function getProtectedAreas(?PresentationCanvas $canvas, ?ConsolePresentationSnapshot $text,
    array $sprites, RendererGridConfig $grid, ?PresentationViewport $viewport = null): array
  {
    $areas = $canvas?->getOverlayProtection() ?? [];
    $width = $grid->columns * $grid->cellWidth;
    $height = $grid->rows * $grid->cellHeight;
    foreach ($sprites as $sprite) {
      $field = $viewport !== null && in_array($sprite->id, $viewport->spriteIds, true);
      $scale = $field ? $viewport->scale : 1;
      $cw = $field ? FieldViewport::TILE_SIZE : $grid->cellWidth;
      $ch = $field ? FieldViewport::TILE_SIZE : $grid->cellHeight;
      $x = ($field ? $viewport->x : 0) + (($sprite->x + 0.5) * $cw - $sprite->width / 2) * $scale;
      $y = ($field ? $viewport->y : 0) + (($sprite->y + 1) * $ch - $sprite->height - $sprite->lift) * $scale;
      // Reserve the neighbouring cell too: native interpolation may still be arriving at the committed cell.
      $pad = $sprite->motion === null ? 6 : max($cw, $ch) * $scale;
      self::appendClipped($areas, $x - $pad, $y - $pad, $sprite->width * $scale + 2 * $pad,
        $sprite->height * $scale + 2 * $pad, $width, $height);
    }
    foreach ($text?->textLayers ?? [] as $layer) {
      if ($layer->layer < PresentationLayerPolicy::UI && $layer->id !== PresentationLayerPolicy::FIELD_PROMPT_ID) { continue; }
      $field = $viewport !== null && in_array($layer->id, $viewport->textLayerIds, true);
      $scale = $field ? $viewport->scale : 1;
      $cw = $field ? FieldViewport::TILE_SIZE : $grid->cellWidth;
      $ch = $field ? FieldViewport::TILE_SIZE : $grid->cellHeight;
      foreach ($layer->runs as $run) {
        self::appendClipped($areas, ($field ? $viewport->x : 0) + $run->column * $cw * $scale,
          ($field ? $viewport->y : 0) + $run->row * $ch * $scale,
          max(1, TerminalText::displayWidth($run->text)) * $cw * $scale, $ch * $scale, $width, $height);
      }
    }
    return $areas;
  }

  private static function appendClipped(array &$areas, float $x, float $y, float $w, float $h, int $width, int $height): void
  {
    $right = min($width, $x + $w);
    $bottom = min($height, $y + $h);
    $x = max(0, $x); $y = max(0, $y);
    if ($right > $x && $bottom > $y) { $areas[] = new CanvasRectangle($x, $y, $right - $x, $bottom - $y); }
  }
}
