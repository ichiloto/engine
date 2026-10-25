<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameConfig;

/**
 * Applies a manifest step's declarative edits (shifts, removals, relocations,
 * then map retirements) to the saved player position and map, so projects
 * need no generated PHP class for inserted/removed rows or columns, newly
 * occupied cells, or a map whose places moved onto another map.
 */
final readonly class DeclaredPositionContentMigration implements ContentMigrationInterface
{
  /**
   * @param list<SavedPositionEdit|MapRetirement> $edits Applied in order.
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
    $mapId = is_string($data['mapId'] ?? null) ? $data['mapId'] : '';
    $place = $position instanceof Vector2 && trim($mapId) !== ''
      ? $this->movePlace($mapId, $position)
      : ['mapId' => $mapId, 'position' => $position];
    $visits = $data['gameState']['visitedMaps'] ?? null;
    $carried = $visits;

    foreach ($this->edits as $edit) {
      if ($edit instanceof MapRetirement) {
        $carried = $this->carryVisit($carried, $edit);
      }
    }

    if ($place['position'] === $position && $carried === $visits) {
      return $payload;
    }

    if ($place['position'] !== $position) {
      $data['playerPosition'] = $place['position'];
      $data['mapId'] = $place['mapId'];
    }

    if ($carried !== $visits) {
      $data['gameState']['visitedMaps'] = $carried;
    }

    $config->applySaveCompatibilityData($data);

    return $payload;
  }

  /**
   * Returns where a saved place stands after this step's edits, in order: a
   * position edit moves it on its map, and a retirement moves it onto its
   * destination, naming that map as the retirement spells it. Tooling uses it
   * to follow a landing through later steps exactly as a save does.
   *
   * @return array{mapId: string, position: Vector2}
   */
  public function movePlace(string $mapId, Vector2 $position): array
  {
    $current = $this->getCurrentMapId($mapId);

    foreach ($this->edits as $edit) {
      if ($this->getCurrentMapId($edit->map) !== $current) {
        continue;
      }

      if ($edit instanceof MapRetirement) {
        $position = $edit->getLanding($position);
        $mapId = $edit->into;
        $current = $this->getCurrentMapId($edit->into);

        continue;
      }

      $position = $edit->applyTo($position);
    }

    return ['mapId' => $mapId, 'position' => $position];
  }

  /**
   * Marks the retirement's destination visited when the save visited the
   * retired map, whose places now stand there. The retired map's own entry
   * is kept.
   */
  private function carryVisit(mixed $visits, MapRetirement $retirement): mixed
  {
    if (! is_array($visits) || isset($visits[$retirement->into])) {
      return $visits;
    }

    $retired = $this->getCurrentMapId($retirement->map);

    foreach (array_keys($visits) as $visited) {
      if (is_string($visited) && $this->getCurrentMapId($visited) === $retired) {
        $visits[$retirement->into] = true;

        break;
      }
    }

    return $visits;
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
