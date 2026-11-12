<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use FilesystemIterator;
use Ichiloto\Engine\Util\Debug;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * What every project map's source says about getting around and what is
 * there to use, read once: the routes between maps and the gates of their
 * events and NPCs. Map sources do not change during a session.
 */
final readonly class ProjectMapIndex
{
  public function __construct(public MapRouteGraph $routes = new MapRouteGraph(), public MapSubjectGates $gates = new MapSubjectGates())
  {
  }

  /** Reads every map under `<assetRoot>/Maps`. Unreadable maps are noted and contribute nothing. */
  public static function scan(string $assetRoot): self
  {
    $mapsDirectory = $assetRoot . '/Maps';
    if (!is_dir($mapsDirectory)) { return new self(); }
    $root = realpath($mapsDirectory);
    $categories = MapCategoryCatalog::load($assetRoot);
    $routes = $events = $npcs = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if (!$file->isFile() || !str_ends_with($file->getFilename(), '.data.php')) { continue; }
      $id = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPath(), strlen($root) + 1));
      if ($id === '') { continue; }
      try {
        $source = MapSourceReader::readEventSource(MapSourceReader::resolvePaths($root, $id));
        $routes[$id] = MapRouteGraph::readMap($id, $source['data'], $source['eventLayer'], $assetRoot, $categories);
        ['events' => $events[$id], 'npcs' => $npcs[$id]] = MapSubjectGates::readMap($source['data']);
      } catch (Throwable $error) {
        Debug::warn(sprintf('Map %s is unavailable to waymarks: %s', $id, $error->getMessage()));
      }
    }
    ksort($routes);
    return new self(new MapRouteGraph($routes), new MapSubjectGates($events, $npcs));
  }
}
