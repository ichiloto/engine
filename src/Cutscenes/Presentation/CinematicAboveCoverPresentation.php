<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Presentation;

use Ichiloto\Engine\Animations\Field\FieldSpriteBounds;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFieldPlane;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationFallbackReport;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;

/**
 * The field images authored on {@see CinematicFieldPlane::ABOVE_COVER},
 * composed as one screen canvas for the cinematic stage plane, so the Game
 * stacks it over the active stage cover. Each image keeps the place, size,
 * frame, layer order and opacity its field sprite would have now; the
 * renderer's step slides, camera eases, bobs and foliage belong to the field
 * world and do not apply on this plane.
 */
final class CinematicAboveCoverPresentation
{
  public const string ID_PREFIX = 'cinematic-above-cover:';

  /** Its owner, the field scene, keeps one report for as long as it lives. */
  private readonly PresentationFallbackReport $fallbacks;

  public function __construct() { $this->fallbacks = new PresentationFallbackReport(); }

  /** Whether the stage plane can draw this sprite's current image: an unturned image with a sprite definition. */
  public static function canLift(GraphicalSpriteProviderInterface $sprite): bool
  {
    $definition = $sprite->getGraphicalSpriteDefinition();
    return $definition !== null && $definition->quarterTurns === 0;
  }

  /** @param iterable<GraphicalSpriteProviderInterface> $sprites */
  public function compose(iterable $sprites, Camera $camera, FieldViewport $view, string $assetRoot,
    int $width, int $height): ?PresentationCanvas
  {
    $images = [];
    foreach ($sprites as $sprite) {
      $definition = $sprite->getGraphicalSpriteDefinition();
      $bounds = FieldSpriteBounds::findSurfaceBounds($sprite, $camera, $view);
      if ($definition === null || $bounds === null || !self::canLift($sprite)) { continue; }
      $source = $definition->sourceRect;
      if ($source === null) {
        $size = PngAssetPreflight::getAvailableSize($assetRoot, $definition->asset);
        if ($size === null) {
          $this->fallbacks->report(sprintf("Above-cover image '%s' is unavailable; it is not drawn this frame.", $definition->asset));
          continue;
        }
        $source = new SpriteSourceRect(0, 0, $size['width'], $size['height']);
      }
      $image = CanvasImage::createClipped(self::ID_PREFIX . $sprite->getGraphicalSpriteId(), $definition->asset,
        $bounds->left, $bounds->top, $bounds->right - $bounds->left, $bounds->bottom - $bounds->top,
        $width, $height, $definition->layer, $source, opacity: $definition->fade?->opacity ?? 1.0);
      if ($image !== null) { $images[] = $image; }
    }
    if ($images === []) { return null; }
    usort($images, static fn(CanvasImage $a, CanvasImage $b): int => $a->layer <=> $b->layer);
    return new PresentationCanvas($width, $height, $images);
  }
}
