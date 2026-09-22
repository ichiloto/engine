<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/** Shared row composition only. Owners retain selection, pagination, input and all outcomes. */
final class MenuRowPainter
{
  /** @var list<CanvasImage> */
  private array $images = [];
  /** @var list<CanvasTextLayer> */
  private array $text = [];

  private function __construct(private int $width, private int $height, private int $layer, private int $contentLayer) {}

  /** Supply only real visible records, not prose or padding rows. Merge returned layers into the owner's canvas.
   * @param list<MenuRow> $rows
   */
  public static function compose(int $width, int $height, string $id, array $rows, MenuRowLayout $layout,
    MenuRowSkin $skin, ?MenuIconRegistry $icons = null, float $time = 0, ?bool $reducedMotion = null,
    int $layer = 1000, ?CanvasRectangle $recordBounds = null, ?int $contentLayer = null): PresentationCanvas
  {
    new PresentationCanvas($width, $height);
    CanvasValidation::id($id);
    $layout->viewport->assertWithin($width, $height);
    $layout->assertFits($skin->metrics);
    if ($recordBounds !== null) {
      $recordBounds->assertWithin($width, $height);
      if (count($rows) !== 1 || $rows[0]->kind !== MenuRowKind::RECORD) {
        throw new InvalidArgumentException('Separate treatment bounds require one real record.');
      }
    }
    if (!is_finite($time) || $time < 0 || !array_is_list($rows) || count($rows) > $layout->capacity()) {
      throw new InvalidArgumentException('Menu rows require finite nonnegative time and an owner-resolved whole-row visible subset.');
    }
    $view = new self($width, $height, $layer, $contentLayer ?? $layer + 5);
    $reducedMotion ??= Accessibility::prefersReducedMotion();
    $ids = [];
    $y = $layout->viewport->y;
    foreach ($rows as $index => $row) {
      if (!$row instanceof MenuRow || isset($ids[$row->id])) {
        throw new InvalidArgumentException('Menu rows require typed records with unique stable IDs.');
      }
      $ids[$row->id] = true;
      $rowHeight = $layout->heightFor($row, $skin->metrics, $icons?->asset($row->icon) !== null);
      if ($y + $rowHeight > $layout->viewport->y + $layout->viewport->height) { $view->overflow($row->id); }
      $bounds = new CanvasRectangle($layout->viewport->x, $y, $layout->viewport->width, $rowHeight);
      if ($recordBounds !== null && ($bounds->x < $recordBounds->x || $bounds->y < $recordBounds->y
        || $bounds->x + $bounds->width > $recordBounds->x + $recordBounds->width
        || $bounds->y + $bounds->height > $recordBounds->y + $recordBounds->height)) {
        throw new InvalidArgumentException('Record identity must fit inside its treatment bounds.');
      }
      $view->row($id . '-' . $row->id, $row, $bounds, $layout, $skin, $icons, $time, $reducedMotion, $recordBounds);
      $y += $rowHeight;
    }
    return new PresentationCanvas($width, $height, $view->images, textLayers: $view->text);
  }

  private function row(string $id, MenuRow $row, CanvasRectangle $bounds, MenuRowLayout $layout,
    MenuRowSkin $skin, ?MenuIconRegistry $icons, float $time, bool $reducedMotion, ?CanvasRectangle $recordBounds): void
  {
    $identityBounds = $bounds;
    $bounds = $recordBounds ?? $bounds;
    $colors = $skin->colors;
    $metrics = $skin->metrics;
    $this->treatment($id . '-normal', $row, $skin, 'normal', $bounds, $this->layer);
    if ($row->selected && !$this->treatment($id . '-selected', $row, $skin, 'selected', $bounds, $this->layer + 1)) {
      $this->fill($id . '-selected', $bounds, $colors['selected'], $this->layer + 1);
      if ($metrics->accentWidth > 0) {
        $this->fill($id . '-accent', new CanvasRectangle($bounds->x, $bounds->y, $metrics->accentWidth, $bounds->height),
          $colors['accent'], $this->layer + 1);
      }
    }
    if ($row->disabled) { $this->treatment($id . '-disabled', $row, $skin, 'disabled', $bounds, $this->layer + 2); }
    if (!$row->kind->isAction() && $metrics->separatorWidth > 0) {
      $this->fill($id . '-separator', new CanvasRectangle($bounds->x, $bounds->y + $bounds->height - $metrics->separatorWidth,
        $bounds->width, $metrics->separatorWidth), $colors['edge'], $this->layer + 3,
        $row->kind === MenuRowKind::HEADING ? $metrics->headingSeparatorOpacity : $metrics->recordSeparatorOpacity);
    }
    if ($row->focused && !$this->treatment($id . '-focus', $row, $skin, 'focus', $bounds, $this->layer + 4)
      && $metrics->focusWidth > 0) {
      $focus = $colors[$row->disabled ? 'edge' : 'focus'];
      $edgeWidth = $metrics->focusWidth;
      foreach ([
        new CanvasRectangle($bounds->x, $bounds->y, $bounds->width, $edgeWidth),
        new CanvasRectangle($bounds->x, $bounds->y + $bounds->height - $edgeWidth, $bounds->width, $edgeWidth),
        new CanvasRectangle($bounds->x, $bounds->y + $edgeWidth, $edgeWidth, $bounds->height - 2 * $edgeWidth),
        new CanvasRectangle($bounds->x + $bounds->width - $edgeWidth, $bounds->y + $edgeWidth, $edgeWidth, $bounds->height - 2 * $edgeWidth),
      ] as $edge => $rect) {
        $this->fill($id . '-focus-' . $edge, $rect, $focus, $this->layer + 4);
      }
    }

    $bounds = $identityBounds;
    $asset = $icons?->asset($row->icon);
    if ($asset !== null) {
      if ($metrics->iconHeight > $bounds->height || $metrics->iconWidth > $bounds->width - 2 * $metrics->padding) {
        throw new InvalidArgumentException('Menu icon box requires a larger row or viewport.');
      }
      array_push($this->images, ...$icons->contain($id . '-icon', $asset,
        new CanvasRectangle($bounds->x + $metrics->padding, $bounds->y + ($bounds->height - $metrics->iconHeight) / 2,
          $metrics->iconWidth, $metrics->iconHeight), $this->contentLayer, $bounds));
    }
    $this->renderLabel($id, $row, $bounds, $layout, $skin, $asset !== null, $icons);
    if ($row->focused && $row->showCursor && $row->kind !== MenuRowKind::BUTTON
      && !$row->disabled && $icons?->cursor !== null) {
      if ($metrics->cursorHeight > $bounds->height
        || $metrics->cursorInset + $metrics->cursorWidth + $metrics->cursorTravel > $metrics->padding) {
        throw new InvalidArgumentException('Menu cursor box and motion must fit the separate leading gutter.');
      }
      array_push($this->images, ...$icons->contain($id . '-cursor', $icons->cursor,
        new CanvasRectangle($bounds->x + $metrics->cursorInset + $metrics->cursorOffset($time, $reducedMotion),
          $bounds->y + ($bounds->height - $metrics->cursorHeight) / 2, $metrics->cursorWidth, $metrics->cursorHeight),
        $this->contentLayer + 1, $bounds));
    }
  }

  private function treatment(string $id, MenuRow $row, MenuRowSkin $skin, string $role, CanvasRectangle $bounds, int $layer): bool
  {
    $art = $skin->treatment($row->kind, $role);
    if ($art === null) { return false; }
    array_push($this->images, ...$art->images($skin->assetRoot ?? throw new InvalidArgumentException('Menu artwork needs its asset root.'),
      $id, $bounds, $layer));
    return true;
  }

  private function renderLabel(string $id, MenuRow $row, CanvasRectangle $bounds, MenuRowLayout $layout,
    MenuRowSkin $skin, bool $hasIcon, ?MenuIconRegistry $icons): void
  {
    $color = $skin->colors[$row->disabled ? 'disabled' : 'text'];
    $metrics = $skin->metrics;
    $separator = $row->kind->isAction() ? 0 : $metrics->separatorWidth;
    $y = $bounds->y + ($layout->rowHeight - $separator - $layout->cellHeight) / 2;
    $iconCells = $hasIcon ? (int)ceil($metrics->iconWidth / $layout->cellWidth) + $metrics->gapCells : 0;
    $length = mb_strlen($row->label, 'UTF-8');
    if ($row->kind->isAction()) {
      $available = $bounds->width - 2 * ($metrics->padding + $iconCells * $layout->cellWidth);
      if ($length === 0) { return; }
      $lines = $layout->lines($row->label, (int)floor($available / $layout->cellWidth));
      $length = max(array_map(mb_strlen(...), $lines));
      $runs = [];
      foreach ($lines as $line => $text) { $runs[] = new PresentationTextRun($line, intdiv($length - mb_strlen($text), 2), $text, $color); }
      $this->text[] = new CanvasTextLayer($id . '-text', $this->contentLayer,
        $bounds->x + ($bounds->width - $length * $layout->cellWidth) / 2, $y,
        new RendererGridConfig($length, count($lines), $layout->cellWidth, $layout->cellHeight), $runs, $bounds);
      return;
    }
    if (count($row->values) !== count($layout->columns)) {
      throw new InvalidArgumentException("Menu row {$row->id} must supply every configured value column, including empty fields.");
    }
    $cells = $layout->textCells($metrics);
    $labelCells = $cells - $layout->valueCells($metrics);
    $runs = [];
    $lines = $layout->lines($row->label, $labelCells - $iconCells);
    $lineCount = count($lines);
    foreach ($lines as $line => $text) {
      if ($text !== '') { $runs[] = new PresentationTextRun($line, $iconCells, $text, $color); }
    }
    $column = $labelCells;
    foreach ($layout->columns as $index => $definition) {
      $column += $metrics->gapCells;
      $value = $row->values[$index];
      if ($value->direction !== null) {
        $left = $bounds->x + $bounds->width - $metrics->padding - $cells * $layout->cellWidth;
        $arrow = $value->direction->getImages($icons, $id . '-value-' . $index,
          new CanvasRectangle($left + $column * $layout->cellWidth, $y,
            $definition->cells * $layout->cellWidth, $layout->cellHeight), $this->contentLayer);
        if ($arrow !== []) {
          array_push($this->images, ...$arrow);
          $column += $definition->cells;
          continue;
        }
      }
      $lines = $layout->lines($value->text, $definition->cells);
      $lineCount = max($lineCount, count($lines));
      foreach ($lines as $line => $text) {
        $length = mb_strlen($text, 'UTF-8');
        $space = $definition->cells - $length;
        $offset = match ($definition->alignment) {
          HorizontalAlignment::LEFT => 0,
          HorizontalAlignment::CENTER => intdiv($space, 2),
          HorizontalAlignment::RIGHT => $space,
        };
        if ($length > 0) {
          $runs[] = new PresentationTextRun($line, $column + $offset, $text,
            $row->disabled ? $color : ($value->color ?? $color));
        }
      }
      $column += $definition->cells;
    }
    if ($runs === []) { return; }
    // All fields share a baseline and one text layer, rather than one layer per value.
    $this->text[] = new CanvasTextLayer($id . '-text', $this->contentLayer,
      $bounds->x + $bounds->width - $metrics->padding - $cells * $layout->cellWidth, $y,
      new RendererGridConfig($cells, $lineCount, $layout->cellWidth, $layout->cellHeight), $runs, $bounds);
  }

  private function overflow(string $id): never
  {
    throw new InvalidArgumentException("Menu row {$id} needs wider columns or viewport; complete text must not be silently truncated.");
  }

  /** Canvas background runs are the existing flat-fill primitive. Clip rounding inside the original row. */
  private function fill(string $id, CanvasRectangle $rect, PresentationColor $color, int $layer, float $opacity = 1): void
  {
    if ($rect->height > 256) {
      $this->fill($id . '-top', new CanvasRectangle($rect->x, $rect->y, $rect->width, 256), $color, $layer, $opacity);
      $this->fill($id . '-bottom', new CanvasRectangle($rect->x, $rect->y + 256, $rect->width, $rect->height - 256), $color, $layer, $opacity);
      return;
    }
    $columns = (int)ceil($rect->width / 256);
    $cellWidth = (int)ceil($rect->width / $columns);
    if ($columns * $cellWidth > $this->width) {
      $firstWidth = (int)floor($this->width / $columns) * $columns;
      $this->fill($id . '-left', new CanvasRectangle($rect->x, $rect->y, $firstWidth, $rect->height), $color, $layer, $opacity);
      $this->fill($id . '-right', new CanvasRectangle($rect->x + $firstWidth, $rect->y, $rect->width - $firstWidth, $rect->height),
        $color, $layer, $opacity);
      return;
    }
    $cellHeight = (int)ceil($rect->height);
    $this->text[] = new CanvasTextLayer($id, $layer,
      min($rect->x, $this->width - $columns * $cellWidth), min($rect->y, $this->height - $cellHeight),
      new RendererGridConfig($columns, 1, $cellWidth, $cellHeight),
      [new PresentationTextRun(0, 0, str_repeat(' ', $columns), background: $color)], $rect, $opacity);
  }
}
