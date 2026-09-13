<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

final readonly class GraphicalSpriteCollector
{
  public function __construct(private GraphicalSpriteProjector $projector = new GraphicalSpriteProjector()) {}

  /** @return list<PresentationSprite> Off-grid sprites are retained for protocol-v1 clipping. */
  public function collect(?SceneInterface $scene): array
  {
    $sprites = [];
    if ($scene instanceof GraphicalSpriteProviderHostInterface) {
      foreach ($scene->getGraphicalSpriteProviders() as $provider) {
        if (($sprite = $this->projector->project($provider, $scene->camera)) !== null) {
          $sprites[] = $sprite;
        }
      }
    }
    return $sprites;
  }
}
