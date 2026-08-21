<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Scenes\Game\GameConfig;

/**
 * Optional second phase for a content migration that needs hydrated actors.
 *
 * The ordinary payload phase still runs before alias and tombstone resolution.
 * This phase runs only after resolution, when project code may safely inspect
 * the current content-backed character graph.
 */
interface PostResolutionContentMigrationInterface
{
  public function migrateResolved(GameConfig $config): void;
}
