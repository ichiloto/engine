<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/** Sparse batching of fully visible, nonoverlapping text on identical font-cell lattices. */
final class MenuCanvasTextBatch
{
  /** @param list<CanvasTextLayer> $layers @return list<CanvasTextLayer> */
  public static function compact(array $layers): array
  {
    foreach ($layers as $index => $a) {
      if (count($layers) <= 64) { break; }
      if (!isset($layers[$index]) || !self::eligible($a)) { continue; }
      foreach ($layers as $other => $b) {
        if (count($layers) <= 64) { break 2; }
        if ($other <= $index || !self::eligible($b) || $a->layer !== $b->layer || $a->opacity !== $b->opacity
          || $a->grid->cellWidth !== $b->grid->cellWidth || $a->grid->cellHeight !== $b->grid->cellHeight) { continue; }
        // Moving this run group must not change the order of any overlapping same-layer text.
        foreach ($layers as $candidate => $text) {
          if ($candidate !== $other && $text->layer === $b->layer && self::overlap($text->bounds, $b->bounds)) { continue 2; }
        }
        $x = min($a->x, $b->x);
        $y = min($a->y, $b->y);
        $cw = $a->grid->cellWidth;
        $ch = $a->grid->cellHeight;
        foreach ([($a->x - $x) / $cw, ($b->x - $x) / $cw, ($a->y - $y) / $ch, ($b->y - $y) / $ch] as $offset) {
          if ($offset !== floor($offset)) { continue 2; }
        }
        $columns = (int)((max($a->bounds->x + $a->bounds->width, $b->bounds->x + $b->bounds->width) - $x) / $cw);
        $rows = (int)((max($a->bounds->y + $a->bounds->height, $b->bounds->y + $b->bounds->height) - $y) / $ch);
        if ($columns > 512 || $rows > 256) { continue; }
        $runs = [];
        foreach ([$a, $b] as $text) {
          foreach ($text->runs as $run) {
            $runs[] = new PresentationTextRun($run->row + (int)(($text->y - $y) / $ch),
              $run->column + (int)(($text->x - $x) / $cw), $run->text, $run->foreground, $run->background);
          }
        }
        $a = new CanvasTextLayer($a->id, $a->layer, $x, $y, new RendererGridConfig($columns, $rows, $cw, $ch),
          $runs, new CanvasRectangle($x, $y, $columns * $cw, $rows * $ch), $a->opacity);
        $layers[$index] = $a;
        unset($layers[$other]);
      }
    }
    return array_values($layers);
  }

  private static function eligible(CanvasTextLayer $text): bool
  {
    if ($text->glyphEffects !== null || $text->runs === []
      || array_any($text->runs, fn($run) => $run->foreground === null || $run->background !== null)) { return false; }
    $clip = $text->clipRect;
    return $clip === null || ($clip->x <= $text->bounds->x && $clip->y <= $text->bounds->y
      && $clip->x + $clip->width >= $text->bounds->x + $text->bounds->width
      && $clip->y + $clip->height >= $text->bounds->y + $text->bounds->height);
  }

  private static function overlap(CanvasRectangle $a, CanvasRectangle $b): bool
  {
    return $a->x < $b->x + $b->width && $a->x + $a->width > $b->x
      && $a->y < $b->y + $b->height && $a->y + $a->height > $b->y;
  }
}
