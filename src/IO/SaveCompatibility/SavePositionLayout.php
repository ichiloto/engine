<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\MapSourceReader;
use RuntimeException;

/** Captures the terrain positions a published or supported playtest layout can give a save. */
final class SavePositionLayout
{
  /**
   * Reads the exact saved project sources using the game's collision resolver.
   * Moving NPCs and quest gates may leave floor available in some playthroughs,
   * so the capture includes all walkable terrain from these sources.
   *
   * @return array{version: int, maps: array<string, list<array{int, int}>>}
   */
  public static function captureFromProject(string $projectRoot): array
  {
    $manifest = SaveCompatibilityManifest::fromProjectRoot($projectRoot, ContentMigrationResolution::WHEN_REQUIRED);
    $assetRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/assets';
    $mapRoot = $assetRoot . '/Maps';
    $ids = MapSourceReader::findMapIds($mapRoot);
    if ($ids === []) {
      throw new RuntimeException('There are no map sources to capture.');
    }

    $dictionaryPath = $mapRoot . '/collisions.php';
    $dictionary = is_file($dictionaryPath) ? MapSourceReader::loadCollisionDictionary($dictionaryPath) : null;
    $positions = [];
    foreach ($ids as $id) {
      $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($mapRoot, $id));
      if ($dictionary === null && ! array_key_exists(MapPhysicalOccupancy::DATA_KEY, $source['data'])) {
        throw new RuntimeException("Cannot record $id without its collision dictionary.");
      }
      $grid = MapCollisionResolver::resolveFromAssets($assetRoot, $source['layers'], $source['data'], $dictionary ?? [])->collisionGrid;
      $cells = [];
      foreach ($grid as $y => $row) {
        foreach ($row as $x => $collision) {
          if (! CollisionType::from($collision)->blocksMovement()) {
            $cells[] = [$x, $y];
          }
        }
      }
      $positions[$id] = $cells;
    }
    return ['version' => $manifest->contentVersion, 'maps' => $positions];
  }
}
