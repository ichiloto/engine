<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Vector2;

/** Opt-in presentation intent; never draws, polls, or owns a Camera/client. */
interface GraphicalSpriteProviderInterface
{
  /** Stable for this object's lifetime and unique among providers included in a frame. */
  public function getGraphicalSpriteId(): string;

  /** Null means this object has no configured graphical representation. */
  public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition;

  /** Logical world/grid position, without terminal glyph offsets. Callers must not mutate it. */
  public function getGraphicalSpriteWorldPosition(): Vector2;
}
