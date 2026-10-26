<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;

/**
 * One declarative map edit a saved player position must follow, declared in
 * a manifest step instead of a project migration class.
 */
interface SavedPositionEdit
{
  /** The map whose saved positions the edit applies to. */
  public string $map { get; }

  /**
   * Returns the position after this edit; positions the edit does not
   * concern keep their coordinates.
   */
  public function applyTo(Vector2 $position): Vector2;
}
