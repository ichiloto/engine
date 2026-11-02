<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Closure;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/** Per-snapshot source/guarded-region limits, not a total-live CPU/GPU memory ceiling. */
final class CanvasImagePreflight
{
  public const MAX_BYTES = 67108864;
  /** The owning host supplies its actual negotiated session; previews may nest independently. */
  private static ?Closure $supports = null;

  /** @param Closure(string): bool $supports */
  public static function runForSession(Closure $supports, Closure $operation): mixed
  {
    $previous = self::$supports;
    self::$supports = $supports;
    try { return $operation(); }
    finally { self::$supports = $previous; }
  }

  /** @param list<CanvasImage> $images
   * @return array{sources: array<string, int>, regions: array<string, int>}
   */
  public static function inspect(array $images, string $root, array $composites = []): array
  {
    $sources = $wholeSources = $regions = $names = [];
    foreach ($images as $image) {
      $size = PngAssetPreflight::inspect($root, $image->asset);
      $crop = PngAssetPreflight::inspect($root, $image->asset, $image->sourceRect);
      $path = realpath($root . DIRECTORY_SEPARATOR . $image->asset);
      $name = $names[$path] ??= $image->asset;
      $sources[$name] = $size['width'] * $size['height'] * 4;
      $rect = [$image->sourceRect?->x ?? 0, $image->sourceRect?->y ?? 0, $crop['width'], $crop['height']];
      // Native canvas sampling adds a two-pixel guard on each edge, even for whole images.
      $region = $name . ':' . implode(',', $rect);
      $bytes = ($crop['width'] + 4) * ($crop['height'] + 4) * 4;
      $regions[$region] = $bytes;
      if ($image->brightness < 1 || $image->flipX || $image->flipY) {
        // Native keeps the guarded original and each distinct transformed region.
        // Normalize -0.0 like the native tone cache; preserve every other f64 bit.
        $tone = bin2hex(pack('E', $image->brightness == 0 ? 0.0 : $image->brightness));
        $regions[$region . ':' . $tone . ':' . (int)$image->flipX . ':' . (int)$image->flipY] = $bytes;
      }
    }
    foreach ($composites as $composite) {
      foreach ($composite->operations as $operation) {
        foreach ($operation->worldSubjects as $subject) {
          foreach ($subject->images as $image) { PngAssetPreflight::inspect($root, $image->asset, $image->sourceRect); }
        }
        foreach ($operation->getAssets() as $asset) {
          $size = PngAssetPreflight::inspect($root, $asset);
          $path = realpath($root . DIRECTORY_SEPARATOR . $asset);
          $name = $names[$path] ??= $asset;
          $sources[$name] = $wholeSources[$name] = $size['width'] * $size['height'] * 4;
        }
      }
    }
    $regionBudget = self::$supports !== null && (self::$supports)(RendererSessionConfig::CANVAS_REGION_BUDGET);
    $chargedSources = $regionBudget ? $wholeSources : $sources;
    if (count($sources) > 1024 || array_sum($chargedSources) > self::MAX_BYTES
      || count($regions) > 4096 || array_sum($regions) > self::MAX_BYTES) {
      throw new CanvasImageBudgetExceeded($sources, $chargedSources, $regions, $regionBudget);
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
