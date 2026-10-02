<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/** A separate colour overlay masked to the current displayed image, not its bounding box. */
final class CanvasImageTint
{
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
