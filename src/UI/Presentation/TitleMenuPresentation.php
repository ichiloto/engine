<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use RuntimeException;

final class TitleMenuPresentation
{
  /** @param list<MenuRow> $commands */
  public static function compose(TitlePresentationCatalog $catalog, TitlePlayback $playback, array $commands,
    bool $showMenu = true): PresentationCanvas
  {
    $bounds = new CanvasRectangle(0, 0, 1350, 720);
    $images = [];
    $composites = [];
    $mix = $playback->nightWeight;
    foreach (['day' => 1.0, 'night' => $mix] as $name => $opacity) {
      if ($opacity <= 0 || ($name === 'day' && $mix >= 1)) { continue; }
      $scene = $catalog->scenes[$name];
      $layer = $name === 'day' ? 0 : 2;
      $ambient = $name === 'day' ? max(0, 1 - 4 * $mix) : max(0, 4 * $mix - 3);
      $weight = $ambient;
      $effects = $scene['effects'] ?? [];
      if (!$playback->reducedMotion && $effects !== [] && $ambient > 0) {
        $operations = [new CanvasCompositeOperation(['type' => 'image', 'asset' => $scene['background'], 'destination' => $bounds->toArray()]),
          ...TitleAmbientPresentation::getOperations($scene['background'], $effects, $playback->elapsed,
            $name === 'night' ? $ambient / $opacity : $ambient)];
        $composites[] = new CanvasComposite('title-' . $name, 1350, 720, $bounds, $operations, $layer, $opacity);
      } else { $images[] = new CanvasImage('title-' . $name, $scene['background'], $bounds, $layer, opacity: $opacity); }
      foreach ($scene['sprites'] ?? [] as $id => $sprite) {
        $image = TitleSpritePresentation::getImage($catalog->assetRoot, 'title-' . $name . '-' . $id,
          $sprite, $playback->elapsed, $weight, $playback->reducedMotion);
        if ($image !== null) {
          $images[] = new CanvasImage($image->id, $image->asset, $image->destination, $layer + 1,
            $image->sourceRect, $image->opacity, $image->clipRect);
        }
      }
    }
    CanvasImagePreflight::inspect($images, $catalog->assetRoot, $composites);
    $base = new PresentationCanvas(1350, 720, $images, composites: $composites);
    if (!$showMenu) { return $base; }
    [$x, $y, $width] = $catalog->logoPlacement;
    $size = PngAssetPreflight::inspect($catalog->assetRoot, $catalog->logo);
    $height = $width * $size['height'] / $size['width'];
    if ($height > $catalog->menu->y - $y) {
      // Retain the authored safe region when a replacement logo changes aspect ratio.
      $scale = ($catalog->menu->y - $y) / $height;
      $x += ($width - $width * $scale) / 2;
      $width *= $scale; $height *= $scale;
    }
    $opacity = $playback->entryOpacity;
    $logo = new CanvasImage('title-logo', $catalog->logo, new CanvasRectangle($x, $y, $width, $height), 4, opacity: $opacity);
    $gleam = $catalog->gleam === null ? null : TitleGleamPresentation::getComposite($logo, $catalog->gleam, $playback->elapsed, $playback->reducedMotion);
    if ($gleam === null) { $images[] = $logo; } else { $composites[] = $gleam; }
    $base = new PresentationCanvas(1350, 720, $images, composites: $composites);
    $view = new MenuCanvas($catalog->theme);
    $view->frame('title-panel', $catalog->menu);
    [$bx, $by, $bw, $bh, $gap] = $catalog->buttons;
    foreach ($commands as $index => $command) {
      $top = $by + $index * ($bh + $gap);
      if ($top + $bh > $catalog->menu->height) { throw new RuntimeException('Title commands exceed their authored panel.'); }
      $view->rows('title', [$command], new MenuRowLayout(new CanvasRectangle($catalog->menu->x + $bx,
        $catalog->menu->y + $top, $bw, $bh), rowHeight: $bh,
        cellWidth: $catalog->theme->metrics->cellWidth, cellHeight: $catalog->theme->metrics->cellHeight));
    }
    $ui = $view->finish();
    $ui = new PresentationCanvas(1350, 720,
      array_map(static fn(CanvasImage $image) => new CanvasImage($image->id, $image->asset, $image->destination,
        $image->layer, $image->sourceRect, $image->opacity * $opacity, $image->clipRect), $ui->images),
      textLayers: array_map(static fn(CanvasTextLayer $text) => new CanvasTextLayer($text->id, $text->layer,
        $text->x, $text->y, $text->grid, $text->runs, $text->clipRect, $text->opacity * $opacity, $text->glyphEffects), $ui->textLayers));
    return MenuCanvas::overlay($base, $ui, $catalog->theme);
  }
}
