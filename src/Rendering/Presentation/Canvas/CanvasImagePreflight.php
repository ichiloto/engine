<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use RuntimeException;

/** Per-snapshot source/guarded-region limits, not a total-live CPU/GPU memory ceiling. */
final class CanvasImagePreflight
{
  public const MAX_BYTES = 67108864;

  /** @param list<CanvasImage> $images
   * @return array{sources: array<string, int>, regions: array<string, int>}
   */
  public static function inspect(array $images, string $root): array
  {
    $sources = $regions = $names = [];
    foreach ($images as $image) {
      $size = PngAssetPreflight::inspect($root, $image->asset);
      $crop = PngAssetPreflight::inspect($root, $image->asset, $image->sourceRect);
      $path = realpath($root . DIRECTORY_SEPARATOR . $image->asset);
      $name = $names[$path] ??= $image->asset;
      $sources[$name] = $size['width'] * $size['height'] * 4;
      $rect = [$image->sourceRect?->x ?? 0, $image->sourceRect?->y ?? 0, $crop['width'], $crop['height']];
      // Native canvas sampling adds a two-pixel guard on each edge, even for whole images.
      $regions[$name . ':' . implode(',', $rect)] = ($crop['width'] + 4) * ($crop['height'] + 4) * 4;
    }
    if (count($sources) > 1024 || array_sum($sources) > self::MAX_BYTES) {
      throw new RuntimeException('Canvas PNG sources exceed the native 64 MiB / 1024-image limit.');
    }
    if (count($regions) > 4096 || array_sum($regions) > self::MAX_BYTES) {
      throw new RuntimeException('Canvas prepared regions exceed the native 64 MiB / 4096-region limit.');
    }
    return ['sources' => $sources, 'regions' => $regions];
  }

  /** Source regions are independent of display size; retain nine-slice cuts for accounting.
   * @param list<CanvasNineSlice> $textures
   * @return list<CanvasImage>
   */
  public static function textures(array $textures): array
  {
    $images = [];
    foreach ($textures as $index => $texture) {
      $bounds = new CanvasRectangle(0, 0,
        max($texture->minimumWidth, $texture->source->width / $texture->density),
        max($texture->minimumHeight, $texture->source->height / $texture->density));
      array_push($images, ...$texture->images('preflight-' . $index, $bounds, 0));
    }
    return $images;
  }
}
