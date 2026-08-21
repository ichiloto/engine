<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

/** A deterministic migration between adjacent engine save-schema versions. */
interface SaveSchemaMigrationInterface
{
  /**
   * @param array<string, mixed> $envelope
   * @return array<string, mixed>
   */
  public function migrate(array $envelope, string $projectId): array;
}
