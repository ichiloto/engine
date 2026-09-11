<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;

/** Explicit, side-effect-free bridge from world intent to screen-grid presentation. */
final class GraphicalSpriteProjector
{
  public function project(GraphicalSpriteProviderInterface $provider, Camera $camera): ?PresentationSprite
  {
    $definition = $provider->getGraphicalSpriteDefinition();
    if ($definition === null) {
      return null;
    }

    $screenPosition = $camera->getScreenSpacePosition($provider->getGraphicalSpriteWorldPosition());
    SpriteValidation::validateSigned32BitRange($screenPosition->x);
    SpriteValidation::validateSigned32BitRange($screenPosition->y);

    // Match Camera's terminal cell conversion; off-grid cells remain valid and are renderer-clipped.
    return new PresentationSprite(
      $provider->getGraphicalSpriteId(), $definition->asset,
      (int) $screenPosition->x, (int) $screenPosition->y,
      $definition->width, $definition->height, $definition->anchor, $definition->layer,
    );
  }
}
