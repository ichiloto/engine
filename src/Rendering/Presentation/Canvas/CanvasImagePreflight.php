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
    return self::inspectCanvasResources($canvases, $root);
  }

  /** Resource admission for still/export roots, independent of per-frame drawing-count limits.
   * Local group limits remain enforced; every root shares one resource pool.
   * A still renderer may filter unsupported optional artwork using its existing diagnostics.
   * @param list<PresentationCanvas> $canvases
   * @param Closure(CanvasImage|CanvasComposite): bool|null $canInspect
   * @return array{sources: array<string, int>, regions: array<string, int>}
   */
  public static function inspectCanvasResources(array $canvases, string $root, ?Closure $canInspect = null): array
  {
    if (!array_is_list($canvases)) { throw new \InvalidArgumentException('Canvas resource roots require a list.'); }
    $images = $composites = [];
    foreach ($canvases as $canvas) {
      CanvasFrameBudget::getCanvases([$canvas]);
      $pending = [[$canvas, null]];
      while ($pending !== []) {
        [$drawn, $color] = array_pop($pending);
        foreach ($drawn->images as $image) {
          if ($canInspect === null || $canInspect($image)) { $images[] = [$image, $color]; }
        }
        foreach ($drawn->composites as $composite) {
          if ($canInspect === null || $canInspect($composite)) { $composites[] = [$composite, $color]; }
        }
        foreach (array_reverse($drawn->groups) as $group) {
          $pending[] = [$group->canvas, CanvasColorTreatment::getEffective($color, $group->colorTransform)];
        }
      }
    }
    return self::inspectResources($images, $root, $composites);
  }

  /** Untreated flat drawings; group consumers must retain their canvas hierarchy.
   * @param list<CanvasImage> $images
   * @param list<CanvasComposite> $composites
   * @return array{sources: array<string, int>, regions: array<string, int>}
   */
  public static function inspect(array $images, string $root, array $composites = []): array
  {
    return self::inspectResources(array_map(static fn(CanvasImage $image): array => [$image, null], $images),
      $root, array_map(static fn(CanvasComposite $composite): array => [$composite, null], $composites));
  }

  /** @param list<array{CanvasImage, ?CanvasColorTreatment}> $images
   * @param list<array{CanvasComposite, ?CanvasColorTreatment}> $composites
   * @return array{sources: array<string, int>, regions: array<string, int>}
   */
  private static function inspectResources(array $images, string $root, array $composites): array
  {
    $sources = $wholeSources = $regions = $names = [];
    foreach ($images as [$image, $color]) {
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
      if ($image->brightness < 1 || $image->flipX || $image->flipY || ($image->rotation ?? 0) !== 0
        || $image->bottomFade !== null || $color !== null) {
        // Native keeps the guarded original and each distinct transformed region.
        // Normalize -0.0 like the native tone cache; preserve every other f64 bit.
        $tone = bin2hex(pack('E', $image->brightness == 0 ? 0.0 : $image->brightness));
        $rotation = $image->rotation ?? 0;
        $variant = $region . ':' . $tone . ':' . (int)$image->flipX . ':' . (int)$image->flipY;
        $variant .= ':' . $rotation;
        if ($image->bottomFade !== null) { $variant .= ':fade:' . self::getFadeKey($image->bottomFade); }
        if ($color !== null) { $variant .= ':color:' . $color->getCacheKey(); }
        $regions[$variant] = CanvasImage::getRotatedRegionBytes($crop['width'], $crop['height'], $rotation);
      }
    }
    foreach ($composites as [$composite, $color]) {
      if ($color !== null) {
        // Composite cache identity excludes placement and ids; colour treats its finished guarded surface.
        $operations = array_map(static fn(CanvasCompositeOperation $operation): array => $operation->data, $composite->operations);
        $key = hash('sha256', serialize([$composite->width, $composite->height, $operations]));
        $regions['composite:' . $key . ':color:' . $color->getCacheKey()] =
          ($composite->width + 4) * ($composite->height + 4) * 4;
      }
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

  private static function getFadeKey(CanvasImageBottomFade $fade): string
  {
    $key = '';
    foreach ([...$fade->line, $fade->depth] as $value) {
      $key .= bin2hex(pack('E', $value == 0 ? 0.0 : $value));
    }
    return $key;
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
