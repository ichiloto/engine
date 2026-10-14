<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageFit;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use InvalidArgumentException;

/** Seekable retained composition, with no audio, combat or world-camera mutation. */
final class CinematicStagePresentation
{
  public static function compose(CinematicStageFrame $frame, array $segments, string $assetRoot,
    int $width, int $height, bool $imageFlips = true, bool $compositing = true): PresentationCanvas
  {
    $images = $composites = [];
    if (!$compositing && ($frame->active || $frame->cover['opacity'] > 0)) {
      throw new InvalidArgumentException('Renderer cannot draw the cinematic stage cover/background.');
    }
    if ($frame->active) {
      $composites[] = self::createFill('cinematic-stage-background', $width, $height,
        $frame->stage->data['background'], PresentationLayerPolicy::CINEMATIC - 501, 1);
    }
    if ($frame->drawsContent) {
      foreach ($segments as $segment) {
        if ($segment['layer'] !== 'image' || $segment['startFrame'] > $frame->contentFrame
          || $segment['endFrame'] < $frame->contentFrame) { continue; }
        foreach ($segment['drawCommands'] as $command) {
          $data = $command['payload'] ?? [];
          if (($data['anchor'] ?? null) !== 'stage' || !($command['visible'] ?? true)) { continue; }
          $flipX = $data['flipX'] ?? false;
          $flipY = $data['flipY'] ?? false;
          if (!$imageFlips && ($flipX || $flipY)) { throw new InvalidArgumentException('Renderer cannot mirror a cinematic stage image.'); }
          $asset = $command['assetId'];
          $size = PngAssetPreflight::getAvailableSize($assetRoot, $asset);
          $columns = $data['columns'];
          $rows = $data['rows'];
          $source = $data['sourceFrame'];
          if ($size === null || $size['width'] % $columns !== 0 || $size['height'] % $rows !== 0
            || $source < 0 || $source >= $columns * $rows) {
            throw new InvalidArgumentException('Cinematic image is unavailable or no longer matches its authored sheet.');
          }
          $frameWidth = intdiv($size['width'], $columns);
          $frameHeight = intdiv($size['height'], $rows);
          $placement = $data['placement'];
          $placement['size'] = CanvasImageFit::parse($data['fit'] ?? 'stretch')->getSize($frameWidth, $frameHeight,
            $placement['size']['width'], $placement['size']['height']);
          $pivot = $data['pivot'] ?? ['x' => .5, 'y' => .5];
          if ($flipX) { $pivot['x'] = 1 - $pivot['x']; }
          if ($flipY) { $pivot['y'] = 1 - $pivot['y']; }
          $rectangle = $frame->getImagePlacement($placement, $pivot, $command['position'], $width, $height);
          $image = CanvasImage::createClipped('cinematic-stage-' . $command['trackId'], $asset,
            $rectangle['x'], $rectangle['y'], $rectangle['width'], $rectangle['height'], $width, $height,
            PresentationLayerPolicy::CINEMATIC + $data['zIndex'],
            new SpriteSourceRect(($source % $columns) * $frameWidth, intdiv($source, $columns) * $frameHeight, $frameWidth, $frameHeight),
            $flipX, $flipY);
          if ($image !== null) {
            $images[] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer, $image->sourceRect,
              $data['opacity'], $image->clipRect, flipX: $image->flipX, flipY: $image->flipY);
          }
        }
      }
    }
    if ($frame->cover['opacity'] > 0) {
      $composites[] = self::createFill('cinematic-stage-cover', $width, $height, $frame->cover['color'],
        PresentationLayerPolicy::TRANSITION, $frame->cover['opacity']);
    }
    return new PresentationCanvas($width, $height, $images, composites: $composites,
      protectedAreas: $frame->active || $frame->cover['opacity'] > 0
        ? [new CanvasRectangle(0, 0, $width, $height)]
        : array_map(static fn(CanvasImage $image): CanvasRectangle => $image->destination, $images));
  }

  /** A one-pixel solid surface avoids allocating a viewport-sized temporary raster. */
  private static function createFill(string $id, int $width, int $height, string $color, int $layer, float $opacity): CanvasComposite
  {
    $hex = match ($color) { 'black' => '000000', 'white' => 'ffffff', default => substr($color, 1) };
    $rgb = ['kind' => 'rgb', 'r' => hexdec(substr($hex, 0, 2)), 'g' => hexdec(substr($hex, 2, 2)),
      'b' => hexdec(substr($hex, 4, 2))];
    return new CanvasComposite($id, 1, 1, new CanvasRectangle(0, 0, $width, $height), [
      new CanvasCompositeOperation(['type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        'brush' => ['type' => 'solid', 'color' => $rgb]]),
    ], $layer, $opacity);
  }
}
