<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;

use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/**
 * Sparse batching of fully visible, nonoverlapping text on identical font-cell lattices. When that
 * leaves a frame over its text-layer budget, solid fills (an Engine-drawn track, divider or backing)
 * are packed onto shared whole-pixel lattices, as the Engine draws its keycaps.
 */
final class CanvasTextBatch
{
  /** @param list<CanvasTextLayer> $layers @return list<CanvasTextLayer> */
  public static function compact(array $layers): array
  {
    return self::packFills(self::batchGlyphs($layers));
  }

  /** @param list<CanvasTextLayer> $layers @return list<CanvasTextLayer> */
  private static function batchGlyphs(array $layers): array
  {
    foreach ($layers as $index => $a) {
      if (count($layers) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { break; }
      if (!isset($layers[$index]) || !self::eligible($a)) { continue; }
      foreach ($layers as $other => $b) {
        if (count($layers) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { break 2; }
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
        if ($columns > RendererGridConfig::MAX_COLUMNS || $rows > RendererGridConfig::MAX_ROWS) { continue; }
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

  /**
   * Packs solid fills sharing a layer and opacity onto whole-pixel lattices, largest group first, until
   * the frame fits its budget. A fill moves only when the only other text it overlaps on its layer is
   * fills moving with it, which paint in their original order (a slider's fill over its track), so the
   * stacking of everything on that layer is unchanged; edges round to whole pixels.
   *
   * @param list<CanvasTextLayer> $layers
   * @return list<CanvasTextLayer>
   */
  private static function packFills(array $layers): array
  {
    if (count($layers) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { return $layers; }
    $fills = array_filter(array_map(self::getFill(...), $layers));
    $key = static fn(int $index): string => "{$layers[$index]->layer}:{$layers[$index]->opacity}";
    $groups = [];
    foreach ($fills as $index => $fill) {
      foreach ($layers as $other => $text) {
        if ($other === $index || $text->layer !== $layers[$index]->layer) { continue; }
        $companion = isset($fills[$other]) && $key($other) === $key($index);
        if (self::overlap($fill[0], $companion ? $fills[$other][0] : $text->paintBounds) && !$companion) { continue 2; }
      }
      $groups[$key($index)][$index] = $fill;
    }
    // A fill overlapping one that cannot move stays too, with everything it overlaps.
    foreach ($groups as $name => $members) {
      do {
        $removed = false;
        foreach ($members as $index => $fill) {
          foreach ($fills as $other => $otherFill) {
            if ($other !== $index && !isset($members[$other]) && $key($other) === $name && self::overlap($fill[0], $otherFill[0])) {
              unset($members[$index]);
              $removed = true;
              continue 2;
            }
          }
        }
      } while ($removed);
      $groups[$name] = $members;
    }
    uasort($groups, static fn(array $a, array $b): int => count($b) <=> count($a));
    $added = [];
    foreach ($groups as $movable) {
      if (count($layers) + count($added) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { break; }
      $first = array_key_first($movable);
      $packed = self::packPixels($layers[$first], $movable);
      if (count($packed) >= count($movable)) { continue; }
      foreach (array_keys($movable) as $index) { unset($layers[$index]); }
      // Indices stay put until every group is packed; the first lattice takes its first fill's place.
      $layers[$first] = $packed[0];
      array_push($added, ...array_slice($packed, 1));
    }
    ksort($layers);
    return [...array_values($layers), ...$added];
  }

  /**
   * A solid fill: spaces on one background covering its whole grid, without glyph effects.
   *
   * @return array{CanvasRectangle, \Ichiloto\Engine\Rendering\Presentation\PresentationColor}|null Its painted area and colour.
   */
  private static function getFill(CanvasTextLayer $text): ?array
  {
    $color = $text->runs[0]->background ?? null;
    if ($color === null || $text->glyphEffects !== null || count($text->runs) !== $text->grid->rows) { return null; }
    foreach ($text->runs as $row => $run) {
      if ($run->row !== $row || $run->column !== 0 || $run->foreground !== null || $run->background !== $color
        || $run->text !== str_repeat(' ', $text->grid->columns)) { return null; }
    }
    $bounds = $text->bounds;
    $clip = $text->clipRect ?? $bounds;
    $left = max($bounds->x, $clip->x);
    $top = max($bounds->y, $clip->y);
    $right = min($bounds->x + $bounds->width, $clip->x + $clip->width);
    $bottom = min($bounds->y + $bounds->height, $clip->y + $clip->height);
    return $right > $left && $bottom > $top ? [new CanvasRectangle($left, $top, $right - $left, $bottom - $top), $color] : null;
  }

  /**
   * Pixel row runs for the fills, a later fill covering an earlier one where they meet, in as few
   * lattices as the grid limits allow.
   *
   * @param array<int, array{CanvasRectangle, \Ichiloto\Engine\Rendering\Presentation\PresentationColor}> $fills In paint order.
   * @return list<CanvasTextLayer>
   */
  private static function packPixels(CanvasTextLayer $first, array $fills): array
  {
    /** @var array<int, list<array{int, int, \Ichiloto\Engine\Rendering\Presentation\PresentationColor}>> $spans Per pixel row: left, right, colour. */
    $spans = [];
    foreach ($fills as [$box, $color]) {
      $left = (int)round($box->x);
      $top = (int)round($box->y);
      $right = max($left + 1, (int)round($box->x + $box->width));
      $bottom = max($top + 1, (int)round($box->y + $box->height));
      for ($y = $top; $y < $bottom; $y++) {
        $row = [];
        foreach ($spans[$y] ?? [] as [$a, $b, $c]) {
          if ($b <= $left || $a >= $right) { $row[] = [$a, $b, $c]; continue; }
          if ($a < $left) { $row[] = [$a, $left, $c]; }
          if ($b > $right) { $row[] = [$right, $b, $c]; }
        }
        $row[] = [$left, $right, $color];
        $spans[$y] = $row;
      }
    }
    $runs = [];
    foreach ($spans as $y => $row) {
      foreach ($row as [$left, $right, $color]) { $runs[] = [$y, $left, $right - $left, $color]; }
    }
    $originLeft = min(array_column($runs, 1));
    $originTop = min(array_column($runs, 0));
    $tiles = [];
    foreach ($runs as [$y, $x, $width, $color]) {
      $tileY = intdiv($y - $originTop, RendererGridConfig::MAX_ROWS);
      // A run crossing a lattice's right side continues in the next one.
      for ($end = $x + $width; $x < $end; ) {
        $tileX = intdiv($x - $originLeft, RendererGridConfig::MAX_COLUMNS);
        $length = min($end, $originLeft + ($tileX + 1) * RendererGridConfig::MAX_COLUMNS) - $x;
        $tiles["$tileY:$tileX"][] = [$y, $x, $length, $color];
        $x += $length;
      }
    }
    $layers = [];
    foreach ($tiles as $key => $parts) {
      [$tileY, $tileX] = array_map(intval(...), explode(':', $key));
      $x = $originLeft + $tileX * RendererGridConfig::MAX_COLUMNS;
      $y = $originTop + $tileY * RendererGridConfig::MAX_ROWS;
      $columns = max(array_map(static fn(array $part): int => $part[1] + $part[2], $parts)) - $x;
      $rows = max(array_column($parts, 0)) + 1 - $y;
      $layers[] = new CanvasTextLayer($first->id . ($layers === [] ? '' : '-pixels-' . count($layers)), $first->layer,
        $x, $y, new RendererGridConfig($columns, $rows, 1, 1), array_map(
          static fn(array $part): PresentationTextRun => new PresentationTextRun($part[0] - $y, $part[1] - $x,
            str_repeat(' ', $part[2]), background: $part[3]), $parts), opacity: $first->opacity);
    }
    return $layers;
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
