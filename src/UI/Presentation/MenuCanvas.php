<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Small shared composition helper. No input, actions or mutable menu selection. */
final class MenuCanvas
{
  private array $images = [];
  private array $text = [];
  private array $fills = [];

  public function __construct(public readonly MenuPresentationCatalog $theme,
    public readonly int $width = PresentationCanvas::DEFAULT_WIDTH, public readonly int $height = PresentationCanvas::DEFAULT_HEIGHT, public readonly float $time = 0)
  {
    $this->fill('menu-background', new CanvasRectangle(0, 0, $width, $height), 'background', 0);
  }

  /** Frame artwork owns both the interior and silhouette; only unskinned panels use a rectangular fill. */
  public function frame(string $id, CanvasRectangle $bounds, string $role = 'panel', int $layer = 10, ?int $borderLayer = null): void
  {
    $art = $this->theme->frames[$role] ?? $this->theme->frames['panel'] ?? null;
    $images = $art?->images($this->theme->assetRoot, $id, $bounds, $layer, $borderLayer) ?? [];
    if ($images !== []) { array_push($this->images, ...$images); }
    else { $this->fill($id, $bounds, 'panel', $layer); }
  }

  /** Theme-owned flat surfaces also serve section headers and scroll indicators. */
  public function surface(string $id, CanvasRectangle $bounds, string $color, int $layer = 20): void
  {
    $this->fill($id, $bounds, $color, $layer);
  }

  /** Decorative semantic roles are optional; a missing role is not an unknown item. */
  public function icon(string $id, string $role, CanvasRectangle $bounds): bool
  {
    $asset = $this->theme->icons?->icons[$role] ?? null;
    if ($asset === null) { return false; }
    $images = MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $asset, $bounds, 31, $bounds);
    array_push($this->images, ...$images);
    return $images !== [];
  }

  /** Already measured lines retain per-line color in one bounded text layer.
   * @param list<array{text: string, color: string}> $lines
   */
  public function textLines(string $id, array $lines, CanvasRectangle $bounds): void
  {
    if ($lines === []) { return; }
    $m = $this->theme->metrics;
    if (count($lines) * $m->cellHeight > $bounds->height) { throw new RuntimeException('Menu text lines exceed their measured viewport.'); }
    $runs = [];
    foreach ($lines as $row => $line) {
      if ($line['text'] !== '') { $runs[] = new PresentationTextRun($row, 0, $line['text'], $this->theme->colors[$line['color']]); }
    }
    $this->text[] = new CanvasTextLayer($id, 30, $bounds->x, $bounds->y,
      new RendererGridConfig((int)floor($bounds->width / $m->cellWidth), count($lines), $m->cellWidth, $m->cellHeight), $runs, $bounds);
  }

  /** Compact reading metadata occupies frame padding, not the prose viewport. */
  public function renderBorderCaption(string $id, string $text, CanvasRectangle $bounds): void
  {
    $m = $this->theme->metrics;
    $height = min($m->cellHeight, (int)floor($bounds->height));
    $width = max(1, (int)floor($m->cellWidth * $height / $m->cellHeight));
    $columns = mb_strlen($text);
    if ($height < 1 || $columns < 1 || $columns * $width > $bounds->width) {
      throw new RuntimeException('Menu border caption exceeds its finite viewport.');
    }
    $this->text[] = new CanvasTextLayer($id, 30, $bounds->x + $bounds->width - $columns * $width, $bounds->y,
      new RendererGridConfig($columns, 1, $width, $height),
      [new PresentationTextRun(0, 0, $text, $this->theme->colors['disabled'])], $bounds);
  }

  /** Reuse the same bounded batching when placing a local menu overlay above an existing canvas. */
  public static function overlay(PresentationCanvas $base, PresentationCanvas $overlay, MenuPresentationCatalog $theme): PresentationCanvas
  {
    $view = new self($theme, $base->width, $base->height);
    $offset = 1 + max([0, ...array_column($base->images, 'layer'), ...array_column($base->textLayers, 'layer'),
      ...array_column($base->indicators, 'layer'), ...array_column($base->composites, 'layer')]);
    $view->images = $base->images;
    foreach ($overlay->images as $image) {
      $view->images[] = new CanvasImage($image->id, $image->asset, $image->destination, $offset + $image->layer,
        $image->sourceRect, $image->opacity, $image->clipRect);
    }
    $view->text = $base->textLayers;
    foreach ($overlay->textLayers as $text) {
      // The local builder's whole-screen background is not part of an overlay.
      if ($text->id === 'menu-background' || str_starts_with($text->id, 'menu-background-part-')) { continue; }
      $view->text[] = new CanvasTextLayer($text->id, $offset + $text->layer, $text->x, $text->y,
        $text->grid, $text->runs, $text->clipRect, $text->opacity, $text->glyphEffects);
    }
    $view->fills = [];
    foreach ($view->text as $text) {
      if ($text->opacity !== 1.0 || $text->glyphEffects !== null || count($text->runs) !== $text->grid->rows) { continue; }
      $color = $text->runs[0]->background ?? null;
      $role = array_search($color, $theme->colors, true);
      if ($role === false) { continue; }
      foreach ($text->runs as $row => $run) {
        if ($run->row !== $row || $run->column !== 0 || $run->foreground !== null || $run->background !== $color
          || $run->text !== str_repeat(' ', $text->grid->columns)) { continue 2; }
      }
      $clip = $text->clipRect ?? $text->bounds;
      if ($clip->x < $text->bounds->x || $clip->y < $text->bounds->y
        || $clip->x + $clip->width > $text->bounds->x + $text->bounds->width
        || $clip->y + $clip->height > $text->bounds->y + $text->bounds->height) { continue; }
      $view->fills[$text->id] = [$clip, $role, $text->layer];
    }
    $view->compactFills();
    $view->compactOutlines();
    $view->compactRules();
    $view->text = MenuCanvasTextBatch::compact($view->text);
    $result = $view->finish();
    $composites = [...$base->composites];
    foreach ($overlay->composites as $composite) {
      $composites[] = new CanvasComposite($composite->id, $composite->width, $composite->height,
        $composite->destination, $composite->operations, $offset + $composite->layer, $composite->opacity, $composite->clipRect);
    }
    CanvasImagePreflight::inspect($result->images, $theme->assetRoot, $composites);
    return new PresentationCanvas($base->width, $base->height, $result->images, $base->indicators, $result->textLayers, $composites);
  }

  public function portrait(string $actorId, CanvasRectangle $bounds, string $id = 'portrait'): void
  {
    $asset = $this->theme->portraits[$actorId] ?? null;
    if ($asset === null) { return; }
    if (isset($this->theme->frames['portrait'])) {
      $this->frame($id . '-frame', $bounds, 'portrait', 21);
    }
    $inset = min($this->theme->metrics->sectionGap, $bounds->width / 4, $bounds->height / 4);
    $box = new CanvasRectangle($bounds->x + $inset, $bounds->y + $inset,
      $bounds->width - 2 * $inset, $bounds->height - 2 * $inset);
    $images = MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $asset, $box, 22, $bounds);
    array_push($this->images, ...$images);
    if ($images === []) { $this->fill($id . '-fallback', $box, 'panel', 22); }
  }

  /** Prose and hints never become record rows or acquire separators. Returns consumed height. */
  public function prose(string $id, string $text, CanvasRectangle $bounds, string $color = 'text',
    HorizontalAlignment $alignment = HorizontalAlignment::LEFT): int
  {
    if ($text === '') { return 0; }
    $m = $this->theme->metrics;
    $cells = (int)floor($bounds->width / $m->cellWidth);
    $lines = self::wrap($text, $cells);
    $height = count($lines) * $m->cellHeight;
    if ($height > $bounds->height) { throw new RuntimeException("Menu prose {$id} exceeds its finite viewport; terminal presentation retained."); }
    $runs = [];
    foreach ($lines as $row => $line) {
      $space = $cells - mb_strlen($line, 'UTF-8');
      $column = match ($alignment) {
        HorizontalAlignment::LEFT => 0, HorizontalAlignment::CENTER => intdiv($space, 2), HorizontalAlignment::RIGHT => $space,
      };
      if ($line !== '') { $runs[] = new PresentationTextRun($row, $column, $line, $this->theme->colors[$color]); }
    }
    $this->text[] = new CanvasTextLayer($id, 30, $bounds->x, $bounds->y,
      new RendererGridConfig((int)floor($bounds->width / $m->cellWidth), count($lines), $m->cellWidth, $m->cellHeight),
      $runs, $bounds);
    return $height;
  }

  /** @return list<string> */
  public static function wrap(string $text, int $cells): array
  {
    if ($cells < 1) { throw new RuntimeException('Menu text has no available width.'); }
    return MenuTextWrap::lines($text, $cells);
  }

  /** A finite view follows the owner's existing index, without taking ownership of navigation.
   * @param list<MenuRow> $rows
   */
  public function rows(string $id, array $rows, MenuRowLayout $layout, ?int $activeIndex = null): void
  {
    if ($rows === []) { return; }
    $heights = array_map(fn(MenuRow $row) => $layout->heightFor($row, $this->theme->rows->metrics,
      $this->theme->icons?->asset($row->icon) !== null), $rows);
    [$first, $last] = $this->visibleRange($id, $heights, $layout->viewport, $activeIndex);
    $canvas = MenuRowPainter::compose($this->width, $this->height, $id,
      array_slice($rows, $first, $last - $first + 1), $layout, $this->theme->rows, $this->theme->icons, $this->time);
    array_push($this->images, ...$canvas->images);
    array_push($this->text, ...$canvas->textLayers);
  }

  /** Shared whole-record viewport; the caller supplies the existing owner's index.
   * @param non-empty-list<int> $heights
   * @return array{int, int} Inclusive first/last indexes.
   */
  public function visibleRange(string $id, array $heights, CanvasRectangle $viewport, ?int $activeIndex,
    ?CanvasRectangle $rangeBounds = null): array
  {
    $first = 0;
    $last = count($heights) - 1;
    if (array_sum($heights) > $viewport->height) {
      if ($activeIndex === null) { throw new RuntimeException("Menu records {$id} exceed their finite viewport."); }
      $activeIndex = max(0, min($last, $activeIndex));
      $available = $viewport->height - $this->theme->metrics->cellHeight;
      $height = $heights[$activeIndex];
      if ($height > $available) { throw new RuntimeException("Menu record {$id} at {$activeIndex} exceeds its finite viewport."); }
      $first = $last = $activeIndex;
      while ($first > 0 && $height + $heights[$first - 1] <= $available) { $height += $heights[--$first]; }
      while ($last < count($heights) - 1 && $height + $heights[$last + 1] <= $available) { $height += $heights[++$last]; }
      $this->prose($id . '-range', sprintf('%d-%d / %d', $first + 1, $last + 1, count($heights)),
        $rangeBounds ?? new CanvasRectangle($viewport->x, $viewport->y + $available,
          $viewport->width, $this->theme->metrics->cellHeight), 'disabled',
        $rangeBounds === null ? HorizontalAlignment::LEFT : HorizontalAlignment::RIGHT);
    }
    return [$first, $last];
  }

  /** A compound record shares row treatments while its identity keeps a separate cursor anchor. */
  public function record(string $id, MenuRow $identity, MenuRowLayout $layout, CanvasRectangle $bounds): void
  {
    $canvas = MenuRowPainter::compose($this->width, $this->height, $id,
      [$identity], $layout, $this->theme->rows, $this->theme->icons, $this->time, layer: 15, recordBounds: $bounds, contentLayer: 30);
    array_push($this->images, ...$canvas->images);
    array_push($this->text, ...$canvas->textLayers);
  }

  /** @param list<\Ichiloto\Engine\IO\ActionHint> $hints */
  public function hints(string $id, array $hints, CanvasRectangle $bounds): int
  {
    $canvas = MenuActionHints::compose($this->width, $this->height, $id, $hints, $this->theme, $bounds);
    array_push($this->images, ...$canvas->images);
    array_push($this->text, ...$canvas->textLayers);
    return MenuActionHints::height($hints, $this->theme, $bounds->width);
  }

  public function finish(): PresentationCanvas
  {
    $this->compactFills();
    $this->compactOutlines();
    $this->compactRules();
    $this->text = MenuCanvasTextBatch::compact($this->text);
    if (count($this->text) > StyledPresentationFrame::MAX_TEXT_LAYERS) { throw new RuntimeException('Menu composition exceeds the existing Canvas text-layer budget: ' . count($this->text)); }
    CanvasImagePreflight::inspect($this->images, $this->theme->assetRoot);
    return new PresentationCanvas($this->width, $this->height, $this->images, textLayers: $this->text);
  }

  private function fill(string $id, CanvasRectangle $bounds, string $color, int $layer): void
  {
    $bounds->assertWithin($this->width, $this->height);
    $columns = (int)ceil($bounds->width / RendererGridConfig::MAX_CELL_SIZE);
    $rows = (int)ceil($bounds->height / RendererGridConfig::MAX_CELL_SIZE);
    $cw = (int)ceil($bounds->width / $columns);
    $ch = (int)ceil($bounds->height / $rows);
    $xs = $this->fillAxis($bounds->width, $this->width, $columns, $cw);
    $ys = $this->fillAxis($bounds->height, $this->height, $rows, $ch);
    $x = min($bounds->x, $this->width - (count($xs) === 1 ? $columns * $cw : ceil($bounds->width)));
    $y = min($bounds->y, $this->height - (count($ys) === 1 ? $rows * $ch : ceil($bounds->height)));
    // Split fragments cannot be merge candidates: joining them would recreate the overflow.
    if (count($xs) === 1 && count($ys) === 1) { $this->fills[$id] = [$bounds, $color, $layer]; }
    foreach ($ys as $yi => [$dy, $nr, $ch]) {
      foreach ($xs as $xi => [$dx, $nc, $cw]) {
        $left = max($bounds->x, $x + $dx);
        $top = max($bounds->y, $y + $dy);
        $right = min($bounds->x + $bounds->width, $x + $dx + $nc * $cw);
        $bottom = min($bounds->y + $bounds->height, $y + $dy + $nr * $ch);
        if ($right <= $left || $bottom <= $top) { continue; }
        $runs = [];
        for ($row = 0; $row < $nr; $row++) {
          $runs[] = new PresentationTextRun($row, 0, str_repeat(' ', $nc), background: $this->theme->colors[$color]);
        }
        $this->text[] = new CanvasTextLayer($id . ($xi + $yi === 0 ? '' : "-part-{$yi}-{$xi}"), $layer,
          $x + $dx, $y + $dy, new RendererGridConfig($nc, $nr, $cw, $ch), $runs,
          new CanvasRectangle($left, $top, $right - $left, $bottom - $top));
      }
    }
  }

  /** Preserve valid grids; only rounded overflow needs one exact remainder strip per axis.
   * @return list<array{int, int, int}> Offset, cells, cell size.
   */
  private function fillAxis(float $extent, int $limit, int $cells, int $size): array
  {
    if ($cells * $size <= $limit) { return [[0, $cells, $size]]; }
    $main = ($cells - 1) * $size;
    return [[0, $cells - 1, $size], [$main, 1, (int)ceil($extent) - $main]];
  }

  /** Join only identical opaque rectangular backings, never text, selection or artwork.
   * This keeps dense default menus within the existing Canvas layer budget without a protocol change.
   */
  private function compactFills(): void
  {
    do {
      $merged = false;
      if (count($this->text) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { return; }
      foreach ($this->fills as $id => [$a, $color, $layer]) {
        foreach ($this->fills as $other => [$b, $otherColor, $otherLayer]) {
          if ($id === $other || $color !== $otherColor || $layer !== $otherLayer) { continue; }
          $x = min($a->x, $b->x);
          $y = min($a->y, $b->y);
          $w = max($a->x + $a->width, $b->x + $b->width) - $x;
          $h = max($a->y + $a->height, $b->y + $b->height) - $y;
          $overlap = max(0, min($a->x + $a->width, $b->x + $b->width) - max($a->x, $b->x))
            * max(0, min($a->y + $a->height, $b->y + $b->height) - max($a->y, $b->y));
          if (abs($w * $h - ($a->width * $a->height + $b->width * $b->height - $overlap)) > 0.00001) { continue; }
          unset($this->fills[$id], $this->fills[$other]);
          $this->text = array_values(array_filter($this->text, fn(CanvasTextLayer $text) => $text->id !== $id && $text->id !== $other));
          $this->fill($id, new CanvasRectangle($x, $y, $w, $h), $color, $layer);
          $merged = true;
          break 2;
        }
      }
    } while ($merged);
  }

  /** Default focus edges are four disjoint rectangles, representable by one sparse pixel-fill grid. */
  private function compactOutlines(): void
  {
    foreach ($this->text as $index => $first) {
      if (count($this->text) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { break; }
      if (!str_ends_with($first->id, '-focus-0')) { continue; }
      $id = substr($first->id, 0, -2);
      $edges = array_filter($this->text, fn(CanvasTextLayer $text) => in_array($text->id,
        [$id . '-0', $id . '-1', $id . '-2', $id . '-3'], true));
      if (count($edges) !== 4) { continue; }
      $x = min(array_column($edges, 'x'));
      $y = min(array_column($edges, 'y'));
      $right = $bottom = 0;
      foreach ($edges as $edge) {
        if ($edge->layer !== $first->layer || $edge->opacity !== $first->opacity || $edge->glyphEffects !== null
          || $edge->bounds != $edge->clipRect || count($edge->runs) !== 1 || $edge->runs[0]->foreground !== null
          || $edge->runs[0]->background === null || $edge->runs[0]->row !== 0 || $edge->runs[0]->column !== 0
          || $edge->runs[0]->text !== str_repeat(' ', $edge->grid->columns)) { continue 2; }
        foreach ([$edge->x - $x, $edge->y - $y, $edge->bounds->width, $edge->bounds->height] as $value) {
          if ($value !== floor($value)) { continue 3; }
        }
        $right = max($right, $edge->x + $edge->bounds->width);
        $bottom = max($bottom, $edge->y + $edge->bounds->height);
      }
      if ($right - $x > RendererGridConfig::MAX_COLUMNS || $bottom - $y > RendererGridConfig::MAX_ROWS) { continue; }
      $runs = [];
      foreach ($edges as $edgeIndex => $edge) {
        for ($row = 0; $row < $edge->bounds->height; $row++) {
          $runs[] = new PresentationTextRun((int)($edge->y - $y) + $row, (int)($edge->x - $x),
            str_repeat(' ', (int)$edge->bounds->width), background: $edge->runs[0]->background);
        }
        unset($this->text[$edgeIndex], $this->fills[$edge->id]);
      }
      $this->text[$index] = new CanvasTextLayer($id, $first->layer, $x, $y,
        new RendererGridConfig((int)($right - $x), (int)($bottom - $y), 1, 1), $runs,
        new CanvasRectangle($x, $y, $right - $x, $bottom - $y), $first->opacity);
    }
    $this->text = array_values($this->text);
  }

  /** Batch compatible horizontal rules as sparse background runs, preserving every painted pixel. */
  private function compactRules(): void
  {
    foreach ($this->text as $index => $a) {
      if (count($this->text) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { break; }
      if (!isset($this->text[$index]) || !str_ends_with($a->id, '-separator') || $a->clipRect === null) { continue; }
      foreach ($this->text as $other => $b) {
        if ($other <= $index || !str_ends_with($b->id, '-separator') || $b->clipRect === null
          || $a->x !== $b->x || $a->layer !== $b->layer || $a->opacity !== $b->opacity
          || $a->grid->columns !== $b->grid->columns || $a->grid->cellWidth !== $b->grid->cellWidth
          || $a->grid->cellHeight !== $b->grid->cellHeight || $a->y > $b->y
          || $a->bounds != $a->clipRect || $b->bounds != $b->clipRect) { continue; }
        $offset = ($b->y - $a->y) / $a->grid->cellHeight;
        $rows = (int)$offset + $b->grid->rows;
        if ($offset !== floor($offset) || $rows > RendererGridConfig::MAX_ROWS) { continue; }
        $runs = $a->runs;
        foreach ($b->runs as $run) {
          $runs[] = new PresentationTextRun($run->row + (int)$offset, $run->column, $run->text, $run->foreground, $run->background);
        }
        $a = new CanvasTextLayer($a->id, $a->layer, $a->x, $a->y,
          new RendererGridConfig($a->grid->columns, $rows, $a->grid->cellWidth, $a->grid->cellHeight), $runs,
          new CanvasRectangle($a->x, $a->y, $a->bounds->width, $rows * $a->grid->cellHeight), $a->opacity);
        $this->text[$index] = $a;
        unset($this->text[$other]);
      }
    }
    $this->text = array_values($this->text);
  }
}
