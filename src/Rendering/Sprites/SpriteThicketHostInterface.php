<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteThicket;

/**
 * A scene whose field has thickets ({@see ThicketSubjectInterface}): it
 * decides from the cell a character occupies how its foliage hides it.
 */
interface SpriteThicketHostInterface
{
  /** How the foliage of a field cell hides a character standing in it, or null when none does or none is drawn. */
  public function findSpriteThicketAt(Vector2 $cell): ?PresentationSpriteThicket;
}
