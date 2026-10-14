<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/** Optional file availability does not relax authored geometry or frame budgets. */
final class CanvasTextureFallback
{
  /** @return array{images: list<CanvasImage>, textLayers: list<CanvasTextLayer>} */
  public static function render(CanvasNineSlice $texture, string $id, CanvasRectangle $bounds, int $layer,
    PresentationColor $fallbackColor, ?string $root, ?CanvasRectangle $clip = null, float $opacity = 1): array
  {
    $images = $texture->images($id, $bounds, $layer, $clip);
    if (!self::isAvailable($texture, $root)) {
      return ['images' => [], 'textLayers' => [self::createFill($id . '-fallback', $bounds, $layer, $fallbackColor, $opacity, $clip)]];
    }
    if ($opacity !== 1.0) {
      $images = array_map(static fn(CanvasImage $image): CanvasImage => new CanvasImage($image->id, $image->asset,
        $image->destination, $image->layer, $image->sourceRect, $opacity, $image->clipRect), $images);
    }
    return ['images' => $images, 'textLayers' => []];
  }

  public static function isAvailable(CanvasNineSlice $texture, ?string $root): bool
  {
    if ($root === null) { return true; }
    if (PngAssetPreflight::getAvailableSize($root, $texture->asset) === null) { return false; }
    PngAssetPreflight::inspect($root, $texture->asset, $texture->source);
    return true;
  }

  /** @param list<CanvasNineSlice> $textures @return list<CanvasNineSlice> */
  public static function getAvailableTextures(array $textures, string $root): array
  {
    return array_values(array_filter($textures, static fn(CanvasNineSlice $texture): bool => self::isAvailable($texture, $root)));
  }

  public static function createFill(string $id, CanvasRectangle $bounds, int $layer, PresentationColor $color,
    float $opacity = 1, ?CanvasRectangle $clip = null): CanvasTextLayer
  {
    $columns = (int)ceil($bounds->width / RendererGridConfig::MAX_CELL_SIZE);
    $rows = (int)ceil($bounds->height / RendererGridConfig::MAX_CELL_SIZE);
    $runs = [];
    for ($row = 0; $row < $rows; $row++) {
      $runs[] = new PresentationTextRun($row, 0, str_repeat(' ', $columns), background: $color);
    }
    return new CanvasTextLayer($id, $layer, $bounds->x, $bounds->y,
      new RendererGridConfig($columns, $rows, (int)ceil($bounds->width / $columns), (int)ceil($bounds->height / $rows)),
      $runs, $clip ?? $bounds, $opacity);
  }
}
