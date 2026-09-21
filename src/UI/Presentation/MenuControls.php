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

/** Shared display-only value/scroll pieces, using the theme and Canvas primitives. */
final class MenuControls
{
  private array $images = [];
  private array $text = [];

  public function __construct(private MenuPresentationCatalog $theme) {}

  public function renderArrow(string $id, string $direction, CanvasRectangle $box, bool $enabled, bool $chevron = false): void
  {
    $symbol = MenuDirection::from($direction);
    $images = $symbol->getImages($this->theme->icons, $id, $box, 40);
    $opacity = $enabled ? 1.0 : 0.35;
    if ($images !== []) {
      foreach ($images as $image) {
        $this->images[] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
          $image->sourceRect, $opacity, $image->clipRect);
      }
      return;
    }
    $glyph = $symbol->getGlyph($chevron);
    $m = $this->theme->metrics;
    $this->text[] = new CanvasTextLayer($id, 40, $box->x + ($box->width - $m->cellWidth) / 2,
      $box->y + ($box->height - $m->cellHeight) / 2, new RendererGridConfig(1, 1, $m->cellWidth, $m->cellHeight),
      [new PresentationTextRun(0, 0, $glyph, $this->theme->colors['accent'])], $box, $opacity);
  }

  public function renderSurface(string $id, string $role, CanvasRectangle $box, PresentationColor $fallback, bool $contain = false): void
  {
    $art = $this->theme->frames[$role] ?? null;
    if ($art === null) { $this->fill($id, $box, $fallback); return; }
    array_push($this->images, ...($contain && $art->left + $art->top + $art->right + $art->bottom === 0
      ? MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $art->asset, $box, 40, $box)
      : $art->images($this->theme->assetRoot, $id, $box, 40)));
  }

  public function renderLevel(string $id, CanvasRectangle $box, float $ratio, PresentationColor $color): void
  {
    $thumb = min($this->theme->metrics->cellHeight, $box->width, $box->height);
    $track = new CanvasRectangle($box->x + $thumb / 2, $box->y + ($box->height - max(2, $thumb / 3)) / 2,
      $box->width - $thumb, max(2, $thumb / 3));
    $this->renderSurface($id . '-track', 'slider.track', $track, $this->theme->colors['edge']);
    if ($ratio > 0) {
      $this->fill($id . '-fill', new CanvasRectangle($track->x, $track->y, $track->width * $ratio, $track->height), $color);
    }
    $this->renderSurface($id . '-thumb', 'slider.thumb',
      new CanvasRectangle($box->x + ($box->width - $thumb) * $ratio, $box->y + ($box->height - $thumb) / 2, $thumb, $thumb),
      $color, true);
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

  public function fill(string $id, CanvasRectangle $box, PresentationColor $color): void
  {
    $columns = (int)ceil($box->width / 256);
    $rows = (int)ceil($box->height / 256);
    $cw = (int)ceil($box->width / $columns);
    $ch = (int)ceil($box->height / $rows);
    $runs = [];
    for ($row = 0; $row < $rows; $row++) {
      $runs[] = new PresentationTextRun($row, 0, str_repeat(' ', $columns), background: $color);
    }
    $this->text[] = new CanvasTextLayer($id, 40, $box->x, $box->y,
      new RendererGridConfig($columns, $rows, $cw, $ch), $runs, $box);
  }

  public function finish(int $width, int $height): PresentationCanvas
  {
    return new PresentationCanvas($width, $height, $this->images, textLayers: $this->text);
  }
}
