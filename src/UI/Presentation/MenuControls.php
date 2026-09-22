<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Shared display-only value/scroll pieces, using the theme and Canvas primitives. */
final class MenuControls
{
  private array $images = [];
  private array $text = [];

  public function __construct(private MenuPresentationCatalog $theme) {}

  public function renderChevron(string $id, string $direction, CanvasRectangle $box, bool $enabled): void
  {
    $symbol = MenuDirection::from($direction);
    $images = $symbol->getImages($this->theme->icons, $id, $box, 40, chevron: true);
    $opacity = $enabled ? 1.0 : 0.35;
    if ($images !== []) {
      foreach ($images as $image) {
        $this->images[] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
          $image->sourceRect, $opacity, $image->clipRect);
      }
      return;
    }
    $glyph = $symbol->getGlyph(chevron: true);
    $m = $this->theme->metrics;
    $this->text[] = new CanvasTextLayer($id, 40, $box->x + ($box->width - $m->cellWidth) / 2,
      $box->y + ($box->height - $m->cellHeight) / 2, new RendererGridConfig(1, 1, $m->cellWidth, $m->cellHeight),
      [new PresentationTextRun(0, 0, $glyph, $this->theme->colors['accent'])], $box, $opacity);
  }

  public function renderSurface(string $id, string $role, CanvasRectangle $box, PresentationColor $fallback,
    bool $contain = false, int $layer = 40): void
  {
    $art = $this->theme->frames[$role] ?? null;
    if ($art === null) { $this->fill($id, $box, $fallback, $layer); return; }
    $images = $contain && $art->left + $art->top + $art->right + $art->bottom === 0
      ? MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $art->asset, $box, $layer, $box)
      : $art->images($this->theme->assetRoot, $id, $box, $layer);
    if ($images === []) { $this->fill($id, $box, $fallback, $layer); }
    else { array_push($this->images, ...$images); }
  }

  public function renderLevel(string $id, CanvasRectangle $box, float $ratio, PresentationColor $color): void
  {
    $m = $this->theme->metrics;
    $thumb = min($m->sliderThumbSize, $box->height);
    if (!is_finite($ratio) || $ratio < 0 || $ratio > 1 || $box->width <= $thumb) {
      throw new InvalidArgumentException('A slider needs a finite unit ratio and room for its track and thumb.');
    }
    $trackHeight = min($m->sliderTrackHeight, $thumb);
    $fillHeight = min($m->sliderFillHeight, $trackHeight);
    $track = new CanvasRectangle($box->x + $thumb / 2, $box->y + ($box->height - $trackHeight) / 2,
      $box->width - $thumb, $trackHeight);
    $this->renderSurface($id . '-track', 'slider.track', $track, $this->theme->colors['edge']);
    if ($ratio > 0) {
      $this->fill($id . '-fill', new CanvasRectangle($track->x, $track->y + ($trackHeight - $fillHeight) / 2,
        $track->width * $ratio, $fillHeight), $color);
    }
    $this->renderSurface($id . '-thumb', 'slider.thumb',
      new CanvasRectangle($box->x + ($box->width - $thumb) * $ratio, $box->y + ($box->height - $thumb) / 2, $thumb, $thumb),
      $color, true, 41);
  }

  /** Offset and extents share the owner's units: pixels for rows, line counts for prose. */
  public function renderScrollbar(string $id, CanvasRectangle $box, float $offset, float $visible, float $total): void
  {
    if (!is_finite($offset) || !is_finite($visible) || !is_finite($total)
      || $visible <= 0 || $total < 0 || $offset < 0 || $offset > max(0, $total - $visible)) {
      throw new InvalidArgumentException('A scrollbar needs finite extents and an offset inside the readable range.');
    }
    if ($total <= $visible) { return; }
    $m = $this->theme->metrics;
    $arrow = min($m->scrollbarWidth, $box->width);
    $inset = $arrow + $m->scrollbarArrowGap;
    if ($box->height <= 2 * $inset) {
      throw new InvalidArgumentException('A scrollbar needs room for its chevrons, gaps and track.');
    }
    $arrowX = $box->x + ($box->width - $arrow) / 2;
    $this->renderChevron($id . '-up', 'up', new CanvasRectangle($arrowX, $box->y, $arrow, $arrow), $offset > 0);
    $this->renderChevron($id . '-down', 'down',
      new CanvasRectangle($arrowX, $box->y + $box->height - $arrow, $arrow, $arrow), $offset < $total - $visible);
    $trackWidth = min($m->scrollbarTrackWidth, $box->width);
    $track = new CanvasRectangle($box->x + ($box->width - $trackWidth) / 2,
      $box->y + $inset, $trackWidth, $box->height - 2 * $inset);
    $this->renderSurface($id . '-track', 'scroll.track', $track, $this->theme->colors['edge']);
    $thumbHeight = min($track->height, max($m->scrollbarMinThumbHeight, $track->height * $visible / $total));
    $thumbY = $track->y + ($track->height - $thumbHeight) * $offset / ($total - $visible);
    $this->renderSurface($id . '-thumb', 'scroll.thumb',
      new CanvasRectangle($track->x, $thumbY, $trackWidth, $thumbHeight), $this->theme->colors['accent'], layer: 41);
  }

  public function renderDivider(string $id, CanvasRectangle $box): void
  {
    $this->fill($id . '-line', new CanvasRectangle($box->x, $box->y + $box->height / 2, $box->width, 1), $this->theme->colors['edge']);
    $asset = $this->theme->icons?->icons['decoration.divider'] ?? null;
    if ($asset !== null) {
      $width = min($box->width, 4 * $this->theme->metrics->cellHeight);
      array_push($this->images, ...MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $asset,
        new CanvasRectangle($box->x + ($box->width - $width) / 2, $box->y, $width, $box->height), 40, $box));
    }
  }

  public function fill(string $id, CanvasRectangle $box, PresentationColor $color, int $layer = 40): void
  {
    $columns = (int)ceil($box->width / RendererGridConfig::MAX_CELL_SIZE);
    $rows = (int)ceil($box->height / RendererGridConfig::MAX_CELL_SIZE);
    $cw = (int)ceil($box->width / $columns);
    $ch = (int)ceil($box->height / $rows);
    $runs = [];
    for ($row = 0; $row < $rows; $row++) {
      $runs[] = new PresentationTextRun($row, 0, str_repeat(' ', $columns), background: $color);
    }
    $this->text[] = new CanvasTextLayer($id, $layer, $box->x, $box->y,
      new RendererGridConfig($columns, $rows, $cw, $ch), $runs, $box);
  }

  public function finish(int $width, int $height): PresentationCanvas
  {
    return new PresentationCanvas($width, $height, $this->images, textLayers: $this->text);
  }
}
