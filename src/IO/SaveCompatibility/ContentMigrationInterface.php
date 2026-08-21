<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

/**
 * A deterministic, project-owned migration between adjacent content versions.
 */
interface ContentMigrationInterface
{
  /**
   * @param array{slot: mixed, config: mixed} $payload The decoded save payload.
   * @return array{slot: mixed, config: mixed} The migrated payload.
   */
  public function migrate(array $payload): array;
}
