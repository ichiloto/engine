<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/** A separate colour overlay masked to the current displayed image, not its bounding box. */
final class CanvasImageTint
{
  /** Combine same-depth, opaque tint surfaces without a raster per subject.
   * @param list<CanvasComposite> $tints
   */
  public static function combine(string $id, array $tints): CanvasComposite
  {
    CanvasCompositeValues::getList($tints, 1, 256);
    if (!$tints[0] instanceof CanvasComposite) { throw new InvalidArgumentException('Combined image tints require typed surfaces.'); }
    $layer = $tints[0]->layer;
    foreach ($tints as $tint) {
      if (!$tint instanceof CanvasComposite || $tint->layer !== $layer || $tint->opacity !== 1.0) {
        throw new InvalidArgumentException('Combined image tints require the same depth and full surface opacity.');
      }
      foreach ($tint->operations as $operation) {
        if ($operation->data['type'] !== 'fill' || $operation->data['masks'] === []
          || array_any($operation->data['masks'], static fn(array $mask): bool => $mask['type'] !== 'image_alpha')) {
          throw new InvalidArgumentException('Combined image tints accept only alpha-masked fills.');
        }
      }
    }
    if (count($tints) === 1) {
      $tint = $tints[0];
      return new CanvasComposite($id, $tint->width, $tint->height, $tint->destination,
        $tint->operations, $tint->layer, $tint->opacity, $tint->clipRect);
    }
    $x = floor(min(array_map(static fn(CanvasComposite $tint): float => $tint->destination->x, $tints)));
    $y = floor(min(array_map(static fn(CanvasComposite $tint): float => $tint->destination->y, $tints)));
    $right = ceil(max(array_map(static fn(CanvasComposite $tint): float => $tint->destination->x + $tint->destination->width, $tints)));
    $bottom = ceil(max(array_map(static fn(CanvasComposite $tint): float => $tint->destination->y + $tint->destination->height, $tints)));
    $operations = [];
    foreach ($tints as $tint) {
      $place = static fn(array $rectangle): array => [
        'x' => $tint->destination->x - $x + $rectangle['x'] * $tint->destination->width / $tint->width,
        'y' => $tint->destination->y - $y + $rectangle['y'] * $tint->destination->height / $tint->height,
        'width' => $rectangle['width'] * $tint->destination->width / $tint->width,
        'height' => $rectangle['height'] * $tint->destination->height / $tint->height,
      ];
      foreach ($tint->operations as $operation) {
        $data = $operation->data;
        $data['destination'] = $place($data['destination']);
        foreach ($data['masks'] as &$mask) { $mask['destination'] = $place($mask['destination']); }
        unset($mask);
        if ($tint->clipRect !== null) {
          $clip = $tint->clipRect;
          $left = $clip->x - $x; $top = $clip->y - $y;
          $data['masks'][] = ['type' => 'polygon', 'contours' => [[[$left, $top], [$left + $clip->width, $top],
            [$left + $clip->width, $top + $clip->height], [$left, $top + $clip->height]]]];
        }
        $operations[] = new CanvasCompositeOperation($data);
      }
    }
    return new CanvasComposite($id, (int)($right - $x), (int)($bottom - $y),
      new CanvasRectangle($x, $y, $right - $x, $bottom - $y), $operations, $layer);
  }

  public static function compose(string $id, CanvasImage $image, PresentationColor $color,
    float $strength, string $assetRoot): CanvasComposite
  {
    return self::composeLayers($id, $image, [['color' => $color, 'strength' => $strength]], $assetRoot);
  }

  /** @param list<array{color: PresentationColor, strength: float}> $tints Ordered tint layers share one surface. */
  public static function composeLayers(string $id, CanvasImage $image, array $tints, string $assetRoot): CanvasComposite
  {
    $size = PngAssetPreflight::inspect($assetRoot, $image->asset);
    PngAssetPreflight::inspect($assetRoot, $image->asset, $image->sourceRect);
    $destination = $image->destination;
    $crop = $image->sourceRect;
    $startX = $startY = 0.0;
    $endX = $endY = 1.0;
    // An axis-aligned affine image keeps its full logical body behind the viewport.
    // Tint only the visible source interval, respecting mirroring before cropping.
    if ($image->rotation === 0 && $image->clipRect !== null) {
      $clip = $image->clipRect;
      $left = max($destination->x, $clip->x); $top = max($destination->y, $clip->y);
      $right = min($destination->x + $destination->width, $clip->x + $clip->width);
      $bottom = min($destination->y + $destination->height, $clip->y + $clip->height);
      if ($right <= $left || $bottom <= $top) {
        throw new InvalidArgumentException('A clipped image tint requires visible source pixels.');
      }
      // A retained boundary is exactly 0 or 1; reconstructing it by subtraction
      // can turn a complete fractional image interval into a span greater than 1.
      $startX = $left === $destination->x ? 0.0 : ($left - $destination->x) / $destination->width;
      $startY = $top === $destination->y ? 0.0 : ($top - $destination->y) / $destination->height;
      $endX = $right === $destination->x + $destination->width ? 1.0 : ($right - $destination->x) / $destination->width;
      $endY = $bottom === $destination->y + $destination->height ? 1.0 : ($bottom - $destination->y) / $destination->height;
      if ($startX !== 0.0 || $startY !== 0.0 || $endX !== 1.0 || $endY !== 1.0) {
        $destination = new CanvasRectangle($left, $top, $right - $left, $bottom - $top);
      }
    }
    $pixelSource = PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_SOURCE_PIXELS);
    [$sourceX, $sourceWidth] = self::getSourceInterval($crop?->x ?? 0,
      $crop?->width ?? $size['width'], $pixelSource ? 1 : $size['width'], $startX, $endX, $image->flipX);
    [$sourceY, $sourceHeight] = self::getSourceInterval($crop?->y ?? 0,
      $crop?->height ?? $size['height'], $pixelSource ? 1 : $size['height'], $startY, $endY, $image->flipY);
    $source = ['x' => $sourceX, 'y' => $sourceY, 'width' => $sourceWidth, 'height' => $sourceHeight];
    $width = (int)ceil($destination->width);
    $height = (int)ceil($destination->height);
    $rectangle = ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];
    $mask = ['type' => 'image_alpha', 'asset' => $image->asset, 'destination' => $rectangle];
    if ($image->flipX) { $mask['flipX'] = true; }
    if ($image->flipY) { $mask['flipY'] = true; }
    if ($crop !== null || $destination !== $image->destination) { $mask[$pixelSource ? 'sourcePixels' : 'source'] = $source; }
    $operations = [];
    foreach (CanvasCompositeValues::getList($tints, 1, 256) as $tint) {
      CanvasCompositeValues::validateKeys($tint, ['color', 'strength']);
      if (!$tint['color'] instanceof PresentationColor) { throw new InvalidArgumentException('Image tints require typed colours.'); }
      $operations[] = new CanvasCompositeOperation(['type' => 'fill', 'destination' => $rectangle,
        'brush' => ['type' => 'solid', 'color' => $tint['color']->toArray()], 'opacity' => $tint['strength'], 'masks' => [$mask]]);
    }
    return new CanvasComposite($id, $width, $height, $destination, $operations,
      $image->layer + 1, $image->opacity, $image->clipRect);
  }

  /** @return array{float, float} Source start and span, resolved from the bounded pixel endpoints. */
  private static function getSourceInterval(int $origin, int $length, int $total,
    float $start, float $end, bool $flip): array
  {
    if ($flip) { [$start, $end] = [1 - $end, 1 - $start]; }
    $left = ($origin + $start * $length) / $total;
    $right = ($origin + $end * $length) / $total;
    return [$left, $right - $left];
  }
}
