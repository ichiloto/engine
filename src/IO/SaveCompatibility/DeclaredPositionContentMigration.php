<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameConfig;

/**
 * Applies a manifest step's declarative position edits (map shifts, then
 * relocations) to the saved player position, so projects need no generated
 * PHP class for inserted rows or columns or for newly occupied cells.
 */
final readonly class DeclaredPositionContentMigration implements ContentMigrationInterface
{
  /**
   * @param list<SavedPositionEdit> $edits Applied in order.
   * @param array<string, string> $mapAliases Declared map aliases, so a save
   * or edit naming a renamed map still matches.
   */
  public function __construct(
    private array $edits,
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

    $moved = $position;

    foreach ($this->edits as $edit) {
      if ($this->getCurrentMapId($edit->map) === $mapId) {
        $moved = $edit->applyTo($moved);
      }
    }

    if ($moved !== $position) {
      $data['playerPosition'] = $moved;
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
