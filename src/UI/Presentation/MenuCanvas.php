<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextBatch;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Small shared composition helper. No input, actions or mutable menu selection. */
final class MenuCanvas
{
  private array $images = [];
  private array $text = [];
  private array $fills = [];
  private array $protectedAreas = [];
  /** @var list<CanvasComposite> Composites an owner's picture brings. */
  private array $composites = [];

  /** Protect readable content or an entire critical control, not its decorative backing. */
  public function protect(CanvasRectangle $bounds): void
  {
    $bounds->assertWithin($this->width, $this->height);
    $this->protectedAreas[] = $bounds;
  }

  public function __construct(public readonly MenuPresentationCatalog $theme,
    public readonly int $width = PresentationCanvas::DEFAULT_WIDTH, public readonly int $height = PresentationCanvas::DEFAULT_HEIGHT,
    public readonly float $time = 0, bool $background = true)
  {
    if ($background) { $this->fill('menu-background', new CanvasRectangle(0, 0, $width, $height), 'background', 0); }
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

  /** Compact primitive outlines share the pixel-preserving batching used by focus treatments. */
  public function renderOutline(string $id, CanvasRectangle $bounds, string $color, int $thickness, int $layer = 20, float $opacity = 1): void
  {
    if ($thickness < 1 || 2 * $thickness >= min($bounds->width, $bounds->height)) {
      throw new RuntimeException('A menu outline requires a positive border and an open interior.');
    }
    foreach ([new CanvasRectangle($bounds->x, $bounds->y, $bounds->width, $thickness),
      new CanvasRectangle($bounds->x, $bounds->y + $bounds->height - $thickness, $bounds->width, $thickness),
      new CanvasRectangle($bounds->x, $bounds->y + $thickness, $thickness, $bounds->height - 2 * $thickness),
      new CanvasRectangle($bounds->x + $bounds->width - $thickness, $bounds->y + $thickness,
        $thickness, $bounds->height - 2 * $thickness)] as $index => $edge) {
      $this->fill($id . '-outline-' . $index, $edge, $color, $layer, $opacity);
    }
  }

  /**
   * A picture an owner composed, such as a battle stage beside a menu's
   * lists: its images and composites placed as given, each inside the
   * canvas, the bounds protected.
   *
   * @param list<CanvasImage> $images
   * @param list<CanvasComposite> $composites
   */
  public function picture(CanvasRectangle $bounds, array $images, array $composites = []): void
  {
    $bounds->assertWithin($this->width, $this->height);
    foreach ($images as $image) {
      if (!$image instanceof CanvasImage) { throw new RuntimeException('A menu picture is made of canvas images and composites.'); }
      $image->destination->assertWithin($this->width, $this->height);
      $this->images[] = $image;
    }
    foreach ($composites as $composite) {
      if (!$composite instanceof CanvasComposite) { throw new RuntimeException('A menu picture is made of canvas images and composites.'); }
      $composite->destination->assertWithin($this->width, $this->height);
      $this->composites[] = $composite;
    }
    $this->protect($bounds);
  }

  /** Decorative semantic roles are optional; a missing role is not an unknown item. */
  public function icon(string $id, string $role, CanvasRectangle $bounds, int $layer = 31): bool
  {
    $asset = $this->theme->icons?->icons[$role] ?? null;
    if ($asset === null) { return false; }
    $images = MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $asset, $bounds, $layer, $bounds);
    array_push($this->images, ...$images);
    if ($images !== []) { $this->protect($bounds); }
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
    $this->protectText($this->text[array_key_last($this->text)]);
  }

  /** A measured detail page: prose lines, and label/value fields styled by the layout's MenuDetailStyle.
   * Column arrangements flow each column from the band top. Lines sharing a column and one text pitch share a
   * text layer. Returns each visible line's top, height and left edge in canvas pixels.
   * @return list<array{y: float, height: int, x: float, width: float}>
   */
  public function details(string $id, MenuDetailLayout $layout, TextPage $page, CanvasRectangle $bounds): array
  {
    $m = $this->theme->metrics;
    $row = $this->theme->rows->metrics;
    $style = $layout->style;
    $metrics = array_slice($layout->measureLines($m->cellHeight), $page->first, count($page->lines));
    $lines = array_slice($layout->lines, $page->first, count($page->lines));
    $columnTops = [];
    $leadY = $bounds->y;
    $positions = [];
    foreach ($lines as $index => $line) {
      $y = $line->band === null ? $leadY : ($columnTops[$line->band] ??= $leadY);
      $positions[] = ['y' => $y, 'height' => $metrics[$index]['height'],
        'x' => $bounds->x + $line->origin * $m->cellWidth, 'width' => $line->span === null ? $bounds->width : $line->span * $m->cellWidth];
      if ($line->band === null) { $leadY += $metrics[$index]['height']; } else { $columnTops[$line->band] += $metrics[$index]['height']; }
    }
    $used = max([$leadY, ...array_values($columnTops)]) - $bounds->y;
    if ($page->source !== $layout->source || $page->columns !== $layout->columns
      || (!$layout->isColumnar && $page->rows > $bounds->height) || $used > $bounds->height
      || $page->columns * $m->cellWidth > $bounds->width) {
      throw new RuntimeException("Menu details {$id} page must match its measured viewport.");
    }
    $blocks = [];
    foreach ($lines as $index => $line) {
      ['y' => $y, 'height' => $height, 'x' => $x, 'width' => $width] = $positions[$index];
      $box = new CanvasRectangle($x, $y, $width, $height);
      $textY = $y + $metrics[$index]['top'];
      if ($line->isHeading) { $this->renderDetailHeading("{$id}-{$index}-heading", $box, $style); }
      $block = array_key_last($blocks);
      if ($block === null || $blocks[$block]['x'] !== $x
        || abs($blocks[$block]['y'] + count($blocks[$block]['lines']) * $m->cellHeight - $textY) > 0.001) {
        $blocks[] = ['x' => $x, 'y' => $textY, 'width' => $width, 'lines' => []];
        $block = array_key_last($blocks);
      }
      $blocks[$block]['lines'][] = $line;
      foreach ($line->items as $item => $value) {
        if ($value['role'] === null) { continue; }
        $this->icon("{$id}-{$index}-icon-{$item}", $value['role'], new CanvasRectangle($x + $value['column'] * $m->cellWidth,
          $textY, $style->iconCells * $m->cellWidth, $m->cellHeight));
      }
      // The data-record rule sits inside the record's last line, as rows keep theirs inside their height.
      if ($line->closesField && $style->separators && $style->separatorText === '' && $row->separatorWidth > 0) {
        $this->fill("{$id}-{$index}-separator", new CanvasRectangle($x, $y + $height - $row->separatorWidth,
          $width, $row->separatorWidth), 'edge', 25, $row->recordSeparatorOpacity);
      }
    }
    foreach ($blocks as $index => ['x' => $x, 'y' => $top, 'width' => $width, 'lines' => $blockLines]) {
      $runs = [];
      foreach ($blockLines as $line => $detail) {
        if ($detail->label !== '') { $runs[] = new PresentationTextRun($line, 0, $detail->label, $this->theme->colors[$style->labelColor]); }
        $values = $detail->items === [] ? [['column' => $detail->column, 'role' => null, 'text' => $detail->text]] : $detail->items;
        foreach ($values as $value) {
          if ($value['role'] === null && $value['text'] !== '') {
            $runs[] = new PresentationTextRun($line, $value['column'], $value['text'], $this->theme->colors[$detail->color]);
          }
        }
      }
      if ($runs === []) { continue; }
      $this->text[] = new CanvasTextLayer($index === 0 ? $id : "{$id}-{$index}", 30, $x, $top,
        new RendererGridConfig((int)floor($width / $m->cellWidth), count($blockLines), $m->cellWidth, $m->cellHeight), $runs, $bounds);
      $this->protectText($this->text[array_key_last($this->text)]);
    }
    return $positions;
  }

  /** A detail heading uses the theme's details.header artwork, else its heading colour role, else no backing. */
  private function renderDetailHeading(string $id, CanvasRectangle $bounds, MenuDetailStyle $style): void
  {
    if (isset($this->theme->frames['details.header'])) { $this->frame($id, $bounds, 'details.header', 20); }
    elseif ($style->headingBackground !== null) { $this->fill($id, $bounds, $style->headingBackground, 20); }
    $rule = $this->theme->rows->metrics->separatorWidth;
    if ($style->headingRuleColor !== null && $rule > 0) {
      $this->fill($id . '-rule', new CanvasRectangle($bounds->x, $bounds->y + $bounds->height - $rule, $bounds->width, $rule),
        $style->headingRuleColor, 21, $style->headingRuleOpacity);
    }
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
    $this->protectText($this->text[array_key_last($this->text)]);
  }

  /** Reuse the same bounded batching when placing a local menu overlay above an existing canvas. */
  public static function overlay(PresentationCanvas $base, PresentationCanvas $overlay, MenuPresentationCatalog $theme): PresentationCanvas
  {
    // Images (mirror flags included), indicators (with the images they mark), labels (measurement, clip, opacity
    // and orientation included) and composites stack through the general composer, above every base layer and
    // label. The overlay's protection is taken before its background is dropped, as it always was.
    $stacked = PresentationCanvas::composeOverlay($base, new PresentationCanvas($overlay->width, $overlay->height,
      $overlay->images, $overlay->indicators, [], $overlay->composites, $overlay->getOverlayProtection(),
      $overlay->presentationOwners, $overlay->labels));
    $view = new self($theme, $base->width, $base->height);
    $offset = 1 + max([0, ...array_column($base->images, 'layer'), ...array_column($base->textLayers, 'layer'),
      ...array_column($base->indicators, 'layer'), ...array_column($base->composites, 'layer'),
      ...array_column($base->labels, 'layer')]);
    $view->images = $stacked->images;
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
    $view->text = CanvasTextBatch::compact($view->text);
    $result = $view->finish();
    CanvasImagePreflight::inspect($result->images, $theme->assetRoot, $stacked->composites);
    return new PresentationCanvas($base->width, $base->height, $result->images, $stacked->indicators, $result->textLayers,
      $stacked->composites, $stacked->protectedAreas, $stacked->presentationOwners, $stacked->labels, $stacked->viewportFit);
  }

  public function portrait(string $actorId, CanvasRectangle $bounds, string $id = 'portrait'): void
  {
    $asset = $this->theme->portraits[$actorId] ?? null;
    if ($asset === null) { return; }
    $this->protect($bounds);
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
    $layout = new MenuTextLayout($text, $cells);
    $height = count($layout->lines) * $m->cellHeight;
    if ($height > $bounds->height) { throw new RuntimeException("Menu prose {$id} exceeds its finite viewport; terminal presentation retained."); }
    $runs = $layout->getRuns($this->theme->colors[$color], alignment: $alignment);
    $this->text[] = new CanvasTextLayer($id, 30, $bounds->x, $bounds->y,
      new RendererGridConfig($cells, count($layout->lines), $m->cellWidth, $m->cellHeight),
      $runs, $bounds);
    $this->protectText($this->text[array_key_last($this->text)]);
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
      MenuRowPainter::canShowIcon($row, $this->theme->icons)), $rows);
    [$first, $last] = $this->visibleRange($id, $heights, $layout->viewport, $activeIndex);
    $canvas = MenuRowPainter::compose($this->width, $this->height, $id,
      array_slice($rows, $first, $last - $first + 1), $layout, $this->theme->rows, $this->theme->icons, $this->time);
    array_push($this->images, ...$canvas->images);
    array_push($this->text, ...$canvas->textLayers);
    array_push($this->protectedAreas, ...$canvas->getOverlayProtection());
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
    array_push($this->protectedAreas, ...$canvas->getOverlayProtection());
  }

  /** @param list<\Ichiloto\Engine\IO\ActionHint> $hints */
  public function hints(string $id, array $hints, CanvasRectangle $bounds, bool $required = false): int
  {
    $canvas = MenuActionHints::compose($this->width, $this->height, $id, $hints, $this->theme, $bounds, $required);
    array_push($this->images, ...$canvas->images);
    array_push($this->text, ...$canvas->textLayers);
    foreach ($canvas->textLayers as $layer) { $this->protectText($layer); }
    foreach ($canvas->images as $image) { $this->protect($image->destination); }
    return MenuActionHints::height($hints, $this->theme, $bounds->width, $required);
  }

  public function finish(): PresentationCanvas
  {
    $this->compactFills();
    $this->compactOutlines();
    $this->compactRules();
    $this->text = CanvasTextBatch::compact($this->text);
    if (count($this->text) > StyledPresentationFrame::MAX_TEXT_LAYERS) { throw new RuntimeException('Menu composition exceeds the existing Canvas text-layer budget: ' . count($this->text)); }
    CanvasImagePreflight::inspect($this->images, $this->theme->assetRoot, $this->composites);
    return new PresentationCanvas($this->width, $this->height, $this->images, textLayers: $this->text,
      composites: $this->composites, protectedAreas: $this->protectedAreas);
  }

  private function protectText(CanvasTextLayer $layer): void
  {
    foreach ($layer->runs as $run) {
      $width = TerminalText::displayWidth($run->text) * $layer->grid->cellWidth;
      if ($width > 0) {
        $this->protect(new CanvasRectangle($layer->x + $run->column * $layer->grid->cellWidth,
          $layer->y + $run->row * $layer->grid->cellHeight, $width, $layer->grid->cellHeight));
      }
    }
  }

  private function fill(string $id, CanvasRectangle $bounds, string $color, int $layer, float $opacity = 1): void
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
    if ($opacity === 1.0 && count($xs) === 1 && count($ys) === 1) { $this->fills[$id] = [$bounds, $color, $layer]; }
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
          new CanvasRectangle($left, $top, $right - $left, $bottom - $top), $opacity);
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

  /** Focus and primitive outline edges are four disjoint rectangles, representable by one sparse pixel-fill grid. */
  private function compactOutlines(): void
  {
    foreach ($this->text as $index => $first) {
      if (count($this->text) <= StyledPresentationFrame::MAX_TEXT_LAYERS) { break; }
      if (!str_ends_with($first->id, '-focus-0') && !str_ends_with($first->id, '-outline-0')) { continue; }
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
          || $a->clipRect->x !== $a->x || $b->clipRect->x !== $b->x
          || $a->clipRect->y !== $a->y || $b->clipRect->y !== $b->y
          || $a->clipRect->width !== $b->clipRect->width
          || $a->clipRect->width > $a->bounds->width || $b->clipRect->width > $b->bounds->width
          || $a->clipRect->height !== $a->bounds->height || $b->clipRect->height !== $b->bounds->height) { continue; }
        $offset = ($b->y - $a->y) / $a->grid->cellHeight;
        $rows = (int)$offset + $b->grid->rows;
        if ($offset !== floor($offset) || $rows > RendererGridConfig::MAX_ROWS) { continue; }
        $runs = $a->runs;
        foreach ($b->runs as $run) {
          $runs[] = new PresentationTextRun($run->row + (int)$offset, $run->column, $run->text, $run->foreground, $run->background);
        }
        $a = new CanvasTextLayer($a->id, $a->layer, $a->x, $a->y,
          new RendererGridConfig($a->grid->columns, $rows, $a->grid->cellWidth, $a->grid->cellHeight), $runs,
          new CanvasRectangle($a->x, $a->y, $a->clipRect->width, $rows * $a->grid->cellHeight), $a->opacity);
        $this->text[$index] = $a;
        unset($this->text[$other]);
      }
    }
    $this->text = array_values($this->text);
  }
}
