<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Scenes\Game\GameConfig;

/**
 * Places a save made with one-column map cells on the two-column grid.
 *
 * `ichiloto upgrade` adds this to a project's content chain after every
 * earlier migration, so those still see the coordinates they were written
 * for. A saved column becomes the cell that now holds it.
 */
final class TwoColumnCellsMigration implements ContentMigrationInterface
{
  public function migrate(array $payload): array
  {
    $config = $payload['config'] ?? null;
    if (!$config instanceof GameConfig) {
      return $payload;
    }
    $data = $config->getSaveCompatibilityData();
    $position = $data['playerPosition'] ?? null;
    if ($position instanceof Vector2) {
      $data['playerPosition'] = new Vector2(intdiv((int)$position->x, MapCell::COLUMNS), (int)$position->y);
      $config->applySaveCompatibilityData($data);
    }
    return $payload;
  }
}
