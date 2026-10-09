<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
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
    $width = (int)ceil($image->destination->width);
    $height = (int)ceil($image->destination->height);
    $rectangle = ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];
    $mask = ['type' => 'image_alpha', 'asset' => $image->asset, 'destination' => $rectangle];
    if ($image->sourceRect !== null) {
      $crop = $image->sourceRect;
      $mask['source'] = ['x' => $crop->x / $size['width'], 'y' => $crop->y / $size['height'],
        'width' => $crop->width / $size['width'], 'height' => $crop->height / $size['height']];
    }
    $operations = [];
    foreach (CanvasCompositeValues::getList($tints, 1, 256) as $tint) {
      CanvasCompositeValues::validateKeys($tint, ['color', 'strength']);
      if (!$tint['color'] instanceof PresentationColor) { throw new InvalidArgumentException('Image tints require typed colours.'); }
      $operations[] = new CanvasCompositeOperation(['type' => 'fill', 'destination' => $rectangle,
        'brush' => ['type' => 'solid', 'color' => $tint['color']->toArray()], 'opacity' => $tint['strength'], 'masks' => [$mask]]);
    }
    return new CanvasComposite($id, $width, $height, $image->destination, $operations,
      $image->layer + 1, $image->opacity, $image->clipRect);
  }
}
