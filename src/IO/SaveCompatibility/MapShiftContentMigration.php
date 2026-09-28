<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameConfig;

/**
 * Applies a manifest step's declarative map shifts to the saved player
 * position, so projects need no generated PHP class for inserted rows or
 * columns.
 */
final readonly class MapShiftContentMigration implements ContentMigrationInterface
{
  /**
   * @param list<MapShift> $shifts Applied in order.
   * @param array<string, string> $mapAliases Declared map aliases, so a save
   * or shift naming a renamed map still matches.
   */
  public function __construct(
    private array $shifts,
    private array $mapAliases = [],
  )
  {
  }

  public function migrate(array $payload): array
  {
    $config = $payload['config'] ?? null;

    if (! $config instanceof GameConfig) {
      return $payload;
    }

    $data = $config->getSaveCompatibilityData();
    $position = $data['playerPosition'] ?? null;
    $mapId = is_string($data['mapId'] ?? null) ? $this->getCurrentMapId($data['mapId']) : '';

    if ($mapId === '' || ! $position instanceof Vector2) {
      return $payload;
    }

    $shifted = $position;

    foreach ($this->shifts as $shift) {
      if ($this->getCurrentMapId($shift->map) === $mapId) {
        $shifted = $shift->applyTo($shifted);
      }
    }

    if ($shifted !== $position) {
      $data['playerPosition'] = $shifted;
      $config->applySaveCompatibilityData($data);
    }

    return $payload;
  }

  /**
   * Follows the alias chain without judging tombstones; content resolution
   * still owns that decision after every migration has run.
   */
  private function getCurrentMapId(string $mapId): string
  {
    $current = trim($mapId);
    $seen = [];

    while (isset($this->mapAliases[$current]) && ! isset($seen[$current])) {
      $seen[$current] = true;
      $current = $this->mapAliases[$current];
    }

    return $current;
  }
}
