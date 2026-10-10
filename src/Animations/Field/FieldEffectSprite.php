<?php

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;

/** Immutable frame intent; the field session, never the renderer, selected it. */
readonly class FieldEffectSprite implements GraphicalSpriteProviderInterface
{
  public function __construct(private string $id, private GraphicalSpriteDefinition $definition,
    private Vector2 $position, private ?PresentationSpriteMotion $motion = null) {}

  public function getGraphicalSpriteId(): string { return $this->id; }
  public function getGraphicalSpriteDefinition(): GraphicalSpriteDefinition { return $this->definition; }
  public function getGraphicalSpriteWorldPosition(): Vector2 { return clone $this->position; }
  public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion { return $this->motion; }
}
