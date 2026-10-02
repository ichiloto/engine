<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * Reads a map's authored files into the gameplay source the field runs on:
 * its layers, its data with every event's area resolved from the event
 * layer, and the collision grid those layers produce.
 *
 * It needs no running scene, so the field, validation and tests all read a
 * map the same way.
 *
 * @package Ichiloto\Engine\Field
 */
final class MapSourceReader
{
  /**
   * MapSourceReader constructor.
   */
  private function __construct()
  {
  }

  /**
   * Resolves the canonical file paths for a map ID.
   *
   * @param string $mapsDirectory The project's `assets/Maps` directory.
   * @param string $filename The logical map filename or any of its PHP file variants.
   * @return array{id: string, data: string, map: string, event: string} The resolved file paths.
   */
  public static function resolvePaths(string $mapsDirectory, string $filename): array
  {
    $mapId = preg_replace('/(\.(data|map|event))?\.php$/', '', $filename) ?: $filename;
    $mapLeafName = basename(str_replace('\\', '/', $mapId));
    $directory = Path::join($mapsDirectory, $mapId);

    return [
      'id' => $mapId,
      'data' => Path::join($directory, "{$mapLeafName}.data.php"),
      'map' => Path::join($directory, "{$mapLeafName}.map.php"),
      'event' => Path::join($directory, "{$mapLeafName}.event.php"),
    ];
  }

  /**
   * Reads a split map definition from its `.data.php` and `.event.php` files
   * and its layers.
   *
   * @param array{id: string, data: string, map: string, event: string} $paths The resolved map file paths.
   * @return array{data: array<string, mixed>, tiles: array<int, string[]>, layers: MapLayerSet} The map source.
   * @throws NotFoundException If any required split-map file is missing.
   */
  public static function readFiles(array $paths): array
  {
    $displayPaths = [];
    foreach (['data', 'event'] as $type) {
      $displayPaths[$type] = $paths['id'] . '/' . basename($paths[$type]);
      if (! file_exists($paths[$type])) {
        throw new NotFoundException("File {$displayPaths[$type]} not found.");
      }
    }

    $layers = MapLayerSource::loadFromDirectory(dirname($paths['data']), $paths['id'], $paths['map']);
    $eventText = MapGridSource::readFile($paths['event'], $displayPaths['event']);
    $eventLayer = self::parseMapLayer($eventText, $displayPaths['event'], 'event');
    $layers->assertMatchingGrid($eventLayer, "Event map {$displayPaths['event']}");
    $map = self::requirePhpFile($paths['data']);

    if (! is_array($map)) {
      throw new NotFoundException("File {$displayPaths['data']} does not return an array.");
    }

    $map['id'] ??= $paths['id'];
    $map['events'] = self::resolveEventDefinitions($map['events'] ?? [], $eventLayer, $displayPaths['event']);

    return ['data' => $map, 'tiles' => $layers->getComposedGrid(), 'layers' => $layers];
  }

  /**
   * Loads a collision dictionary from a file.
   *
   * PHP normalizes numeric-string array keys such as `"8"` to integers, so
   * single decimal digit keys are valid tile glyphs alongside string keys.
   *
   * @param string $filename The filename of the collision dictionary.
   * @return array<int|string, CollisionType|array<int|string, CollisionType>> The collision dictionary.
   * @throws NotFoundException
   */
  public static function loadCollisionDictionary(string $filename): array
  {
    if (! file_exists($filename) ) {
      throw new NotFoundException("File $filename not found.");
    }

    $dictionary = self::requirePhpFile($filename);

    if (! is_array($dictionary)) {
      throw new NotFoundException("File $filename does not return an array.");
    }

    try {
      MapCollisionResolver::validateDictionary($dictionary);
    } catch (InvalidArgumentException $error) {
      throw new NotFoundException($error->getMessage(), previous: $error);
    }

    return $dictionary;
  }

  /**
   * Requires an authored PHP asset without exposing the caller's local scope.
   *
   * The data member may contain authored PHP. Loading it inside a dedicated
   * static closure prevents its local variables from replacing loader state.
   * Grid members are parsed as literal nowdocs and are never required.
   *
   * @param string $filename The PHP asset to load.
   * @return mixed The value returned by the asset.
   */
  private static function requirePhpFile(string $filename): mixed
  {
    return (static function (string $isolatedFilename): mixed {
      return require $isolatedFilename;
    })($filename);
  }

  /**
   * Parses a text-based map layer into symbol rows.
   *
   * @param string $layer The raw layer content.
   * @param string $filename The source filename.
   * @param string $fieldName The layer label used in validation errors.
   * @return array<int, string[]> The parsed symbol grid.
   */
  private static function parseMapLayer(string $layer, string $filename, string $fieldName): array
  {
    $rows = preg_split('/\r\n|\n|\r/', rtrim($layer, "\r\n")) ?: [];

    if ($rows === []) {
      return [];
    }

    foreach ($rows as $rowIndex => $row) {
      if (! is_string($row)) {
        throw new InvalidArgumentException("{$fieldName} row {$rowIndex} in {$filename} must be a string.");
      }
    }

    return array_map(
      static fn(string $row): array => TerminalText::visibleSymbols($row),
      $rows
    );
  }

  /**
   * Resolves event definitions against the event overlay.
   *
   * @param array<int|string, array<string, mixed>> $events The map event definitions.
   * @param array<int, string[]> $eventLayer The parsed event overlay.
   * @param string $filename The event-layer filename.
   * @return array<int, array<string, mixed>> The resolved runtime event data.
   */
  private static function resolveEventDefinitions(array $events, array $eventLayer, string $filename): array
  {
    $areas = self::extractEventAreas($eventLayer, $filename);

    if ($events === []) {
      if ($areas !== []) {
        throw new InvalidArgumentException("Event markers were found in {$filename}, but no event definitions exist in the map data.");
      }

      return [];
    }

    $resolvedEvents = [];

    foreach ($events as $marker => $eventDefinition) {
      if (! is_array($eventDefinition)) {
        throw new InvalidArgumentException("Invalid event definition found in {$filename}.");
      }

      if (isset($eventDefinition['area']) && ! is_string($marker)) {
        $resolvedEvents[] = $eventDefinition;
        continue;
      }

      $resolvedMarker = is_string($marker) ? $marker : ($eventDefinition['marker'] ?? null);

      if (! is_string($resolvedMarker) || TerminalText::displayWidth($resolvedMarker) !== 1) {
        throw new InvalidArgumentException("Events in split map data must be keyed by a single-character marker or declare one explicitly.");
      }

      $area = $areas[$resolvedMarker] ?? throw new InvalidArgumentException("Event marker '{$resolvedMarker}' was not found in {$filename}.");
      unset($areas[$resolvedMarker]);
      $eventDefinition['area'] = $area;
      $eventDefinition['marker'] = $resolvedMarker;
      $resolvedEvents[] = $eventDefinition;
    }

    if ($areas !== []) {
      $unusedMarkers = implode(', ', array_keys($areas));
      throw new InvalidArgumentException("Unmapped event markers found in {$filename}: {$unusedMarkers}.");
    }

    return $resolvedEvents;
  }

  /**
   * Extracts rectangular event areas from the event overlay.
   *
   * @param array<int, string[]> $eventLayer The parsed event overlay.
   * @param string $filename The event-layer filename.
   * @return array<string, array{x: int, y: int, width: int, height: int}> The resolved areas keyed by marker.
   */
  private static function extractEventAreas(array $eventLayer, string $filename): array
  {
    $bounds = [];

    foreach ($eventLayer as $y => $row) {
      foreach ($row as $x => $tile) {
        $marker = TerminalText::stripAnsi($tile);

        if (trim($marker) === '') {
          continue;
        }

        if (! isset($bounds[$marker])) {
          $bounds[$marker] = [
            'minX' => $x,
            'maxX' => $x,
            'minY' => $y,
            'maxY' => $y,
          ];
          continue;
        }

        $bounds[$marker]['minX'] = min($bounds[$marker]['minX'], $x);
        $bounds[$marker]['maxX'] = max($bounds[$marker]['maxX'], $x);
        $bounds[$marker]['minY'] = min($bounds[$marker]['minY'], $y);
        $bounds[$marker]['maxY'] = max($bounds[$marker]['maxY'], $y);
      }
    }

    $areas = [];

    foreach ($bounds as $marker => $markerBounds) {
      for ($y = $markerBounds['minY']; $y <= $markerBounds['maxY']; $y++) {
        for ($x = $markerBounds['minX']; $x <= $markerBounds['maxX']; $x++) {
          $cell = TerminalText::stripAnsi($eventLayer[$y][$x] ?? ' ');

          if ($cell !== $marker) {
            throw new InvalidArgumentException("Event marker '{$marker}' in {$filename} must occupy a solid rectangle.");
          }
        }
      }

      $areas[$marker] = [
        'x' => $markerBounds['minX'],
        'y' => $markerBounds['minY'],
        'width' => $markerBounds['maxX'] - $markerBounds['minX'] + 1,
        'height' => $markerBounds['maxY'] - $markerBounds['minY'] + 1,
      ];
    }

    return $areas;
  }
}
