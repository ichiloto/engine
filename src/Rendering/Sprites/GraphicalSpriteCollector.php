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
    return $this->collectPresentation($scene)['sprites'];
  }

  /**
   * The frame's sprites, and the providers the scene presents this frame
   * without one: a subject with no graphical presentation, which a graphical
   * field can only show by its terminal glyph.
   *
   * @return array{sprites: list<PresentationSprite>, unpresented: list<string>} Unpresented provider ids, in order.
   */
  public function collectPresentation(?SceneInterface $scene): array
  {
    $sprites = $unpresented = [];
    if ($scene instanceof GraphicalSpriteProviderHostInterface) {
      $thickets = $scene instanceof SpriteThicketHostInterface ? $scene : null;
      foreach ($scene->getGraphicalSpriteProviders() as $provider) {
        // A character is hidden by the foliage of the cell it occupies, the cell it is sliding to.
        $thicket = $thickets !== null && $provider instanceof ThicketSubjectInterface && $provider->standsInFoliage
          ? $thickets->findSpriteThicketAt($provider->getGraphicalSpriteWorldPosition()) : null;
        if (($sprite = $this->projector->project($provider, $scene->camera, $thicket)) !== null) {
          $sprites[] = $sprite;
        } else {
          $unpresented[] = $provider->getGraphicalSpriteId();
        }
      }
    }
    return ['sprites' => $sprites, 'unpresented' => $unpresented];
  }
}
