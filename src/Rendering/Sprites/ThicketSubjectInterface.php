<?php

namespace Ichiloto\Engine\Rendering\Sprites;

/**
 * A field sprite that stands on the ground of the cell it occupies, as a
 * character does, so the foliage of a thicket there hides its lower part
 * ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetThicket}). The player,
 * NPCs and cinematic cast are; world objects, effects and guidance are not.
 */
interface ThicketSubjectInterface extends GraphicalSpriteProviderInterface
{
  /**
   * Whether it stands among its cell's foliage now: a character does; an
   * object character such as a chest, as RPG Maker draws it, or art held on
   * a world object does not.
   */
  public bool $standsInFoliage { get; }
}
