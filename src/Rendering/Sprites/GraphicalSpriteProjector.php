<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteThicket;

/** Explicit, side-effect-free bridge from world intent to screen-grid presentation. */
final class GraphicalSpriteProjector
{
  /** @param PresentationSpriteThicket|null $thicket How the foliage of its cell hides it, fitted to its height. */
  public function project(GraphicalSpriteProviderInterface $provider, Camera $camera,
    ?PresentationSpriteThicket $thicket = null): ?PresentationSprite
  {
    $definition = $provider->getGraphicalSpriteDefinition();
    if ($definition === null) {
      return null;
    }

    $screenPosition = $provider instanceof ScreenSpaceSpriteProviderInterface
      ? $provider->getGraphicalSpriteWorldPosition()
      : $camera->getScreenSpacePosition($provider->getGraphicalSpriteWorldPosition());
    SpriteValidation::validateSigned32BitRange($screenPosition->x);
    SpriteValidation::validateSigned32BitRange($screenPosition->y);

    // Match Camera's terminal cell conversion; off-grid cells remain valid and are renderer-clipped.
    return new PresentationSprite(
      $provider->getGraphicalSpriteId(), $definition->asset,
      (int) $screenPosition->x, (int) $screenPosition->y,
      $definition->width, $definition->height, $definition->anchor, $definition->layer,
      $definition->sourceRect, $provider->getGraphicalSpriteMotion(), $definition->lift, $definition->quarterTurns,
      $definition->pivot, $definition->bob, $definition->fade, $thicket?->fitHeight($definition->height),
    );
  }
}
