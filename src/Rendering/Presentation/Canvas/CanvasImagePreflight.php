<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Closure;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/** Per-snapshot source/guarded-region limits, not a total-live CPU/GPU memory ceiling. */
final class CanvasImagePreflight
{
  public const MAX_BYTES = 67108864;
  /** Compatibility entry point; image and composite consumers share the same host scope.
   * @param Closure(string): bool $supports
   */
  public static function runForSession(Closure $supports, Closure $operation): mixed
  {
    return PresentationSession::runForSession($supports, $operation);
  }

  /** All descendants and roots share the same source and prepared-region accounting. */
  public static function inspectCanvases(array $canvases, string $root): array
  {
    CanvasFrameBudget::assertCanvases($canvases);
    $images = $composites = [];
    foreach (CanvasFrameBudget::getCanvases($canvases) as $canvas) {
      array_push($images, ...$canvas->images);
      array_push($composites, ...$canvas->composites);
    }
    return self::inspect($images, $root, $composites);
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
      if ($image->brightness < 1 || $image->flipX || $image->flipY || ($image->rotation ?? 0) !== 0) {
        // Native keeps the guarded original and each distinct transformed region.
        // Normalize -0.0 like the native tone cache; preserve every other f64 bit.
        $tone = bin2hex(pack('E', $image->brightness == 0 ? 0.0 : $image->brightness));
        $rotation = $image->rotation ?? 0;
        $variant = $region . ':' . $tone . ':' . (int)$image->flipX . ':' . (int)$image->flipY;
        $regions[$variant . ':' . $rotation] = CanvasImage::getRotatedRegionBytes($crop['width'], $crop['height'], $rotation);
      }
    }
    foreach ($composites as $composite) {
      foreach ($composite->operations as $operation) {
        foreach ($operation->worldSubjects as $subject) {
          foreach ($subject->images as $image) { PngAssetPreflight::inspect($root, $image->asset, $image->sourceRect); }
        }
        if ($operation->textureStrip !== null) {
          $data = $operation->data;
          $asset = $data['asset'];
          $rect = new SpriteSourceRect(...$data['sourceRect']);
          $size = PngAssetPreflight::inspect($root, $asset);
          PngAssetPreflight::inspect($root, $asset, $rect);
          $path = realpath($root . DIRECTORY_SEPARATOR . $asset);
          $name = $names[$path] ??= $asset;
          $sources[$name] = $size['width'] * $size['height'] * 4;
          $regions[$name . ':' . implode(',', $rect->toArray())] = ($rect->width + 4) * ($rect->height + 4) * 4;
          continue;
        }
        foreach ($operation->getAssets() as $asset) {
          $size = PngAssetPreflight::inspect($root, $asset);
          $path = realpath($root . DIRECTORY_SEPARATOR . $asset);
          $name = $names[$path] ??= $asset;
          $sources[$name] = $wholeSources[$name] = $size['width'] * $size['height'] * 4;
        }
      }
    }
    $regionBudget = PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_REGION_BUDGET);
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
