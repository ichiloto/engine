<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Closure;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Util\Debug;
use SplPriorityQueue;
use Throwable;

/**
 * Every authored way between the project's maps, read from map sources.
 *
 * A route is a transfer event, a legacy transfer trigger, or a reusable
 * script event whose script transfers the player (a repeatable transport).
 * One-shot scripts and cinematics move the player as a story consequence,
 * so they are not routes. The graph is static for a session; whether a
 * route can be used now is decided per query from world state.
 */
final readonly class MapRouteGraph
{
  /** Plain passages win over scripted transports on otherwise equal routes. */
  private const int PASSAGE_COST = 1000;
  private const int SCRIPTED_COST = 1001;
  private const int MAX_SCRIPT_DEPTH = 16;

  /** @param array<string, list<MapRoute>> $routes Routes keyed by source map. */
  public function __construct(public array $routes = [])
  {
  }

  /**
   * Reads one map's outgoing routes from its resolved source.
   *
   * @param array<string, mixed> $map Resolved map data (MapSourceReader).
   * @param array<int, string[]> $grid The map's event-layer grid, for landmark geometry.
   * @return list<MapRoute>
   */
  public static function readMap(string $mapId, array $map, array $grid, string $assetRoot,
    ?MapCategoryCatalog $categories = null): array
  {
    $landmarks = [];
    try {
      foreach (MapLandmarkCatalog::readMap($map, $grid, $categories)->landmarks as $landmark) {
        $landmarks[$landmark->id] = $landmark;
      }
    } catch (Throwable $error) {
      Debug::warn(sprintf('Map %s landmarks are unusable for routes: %s', $mapId, $error->getMessage()));
    }
    $claims = [];
    foreach (is_array($map['landmarks'] ?? null) ? $map['landmarks'] : [] as $entry) {
      $marker = is_array($entry) ? ($entry['anchor']['event'] ?? null) : null;
      if (is_string($marker) && isset($landmarks[$entry['id'] ?? ''])) { $claims[$marker] = $landmarks[$entry['id']]; }
    }

    $routes = [];
    foreach ($map['events'] ?? [] as $event) {
      $marker = $event['marker'] ?? null;
      $class = strval($event['class'] ?? '');
      if (!is_string($marker) || $marker === '' || !is_array($event['area'] ?? null)) { continue; }
      $data = is_array($event['data'] ?? null) ? $event['data'] : [];
      $destinations = [];
      $scripted = false;
      if (is_a($class, TransferPlayerTrigger::class, true)) {
        $destination = $data['destinationMap'] ?? null;
        if (is_string($destination) && trim($destination) !== '') { $destinations[] = trim($destination); }
      } elseif (is_a($class, ScriptEventTrigger::class, true) && ($data['reusable'] ?? true)) {
        $scripted = true;
        $script = $data['script'] ?? null;
        $scriptId = trim(strval($data['scriptId'] ?? ''));
        if (!is_array($script) && $scriptId !== '') { $script = self::readScript($assetRoot, $scriptId); }
        $visited = [];
        $destinations = self::findTransferDestinations($script, $assetRoot, $visited);
      } else {
        continue;
      }
      $landmark = $claims[$marker] ?? $landmarks['@event:' . $marker] ?? null;
      foreach (array_unique($destinations) as $destination) {
        if ($destination === $mapId) { continue; }
        $routes[] = new MapRoute($mapId, $destination, $marker, CellArea::fromArray($event['area']),
          array_values(array_filter((array) ($event['conditions'] ?? []), 'is_array')), $scripted,
          $landmark?->id, $landmark?->secret ?? false);
      }
    }
    foreach ($map['triggers'] ?? [] as $index => $trigger) {
      $destination = is_array($trigger) ? ($trigger['destinationMap'] ?? null) : null;
      if (!is_string($destination) || trim($destination) === '' || trim($destination) === $mapId) { continue; }
      $area = $trigger['trigger_area'] ?? [];
      $id = '@legacy:' . strval($trigger['id'] ?? '@' . $index);
      $routes[] = new MapRoute($mapId, trim($destination), $id, CellArea::fromArray([
        'x' => $area['x'] ?? 0, 'y' => $area['y'] ?? 0, 'width' => $area['width'] ?? 1, 'height' => $area['height'] ?? 1,
      ]), landmarkId: $landmarks[$id]->id ?? null);
    }
    return $routes;
  }

  /**
   * Finds the first route to take from one map toward another.
   *
   * @param Closure(MapRoute): bool $canUse Decides whether a route is usable now.
   * @return MapRoute|null The first step of the cheapest usable path, or null when none exists.
   */
  public function findNextRoute(string $from, string $to, Closure $canUse): ?MapRoute
  {
    if ($from === $to) { return null; }
    $best = [$from => 0];
    $first = [];
    $queue = new SplPriorityQueue();
    $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $queue->insert($from, 0);
    while (!$queue->isEmpty()) {
      ['data' => $map, 'priority' => $priority] = $queue->extract();
      $cost = -$priority;
      if ($cost > ($best[$map] ?? PHP_INT_MAX)) { continue; }
      if ($map === $to) { return $first[$map]; }
      foreach ($this->routes[$map] ?? [] as $route) {
        $next = $cost + ($route->scripted ? self::SCRIPTED_COST : self::PASSAGE_COST);
        if ($next >= ($best[$route->destination] ?? PHP_INT_MAX) || !$canUse($route)) { continue; }
        $best[$route->destination] = $next;
        $first[$route->destination] = $first[$map] ?? $route;
        $queue->insert($route->destination, -$next);
      }
    }
    return null;
  }

  /**
   * Collects every `transfer` destination in a command tree, following
   * Common Events, whatever branch or choice arm holds it.
   *
   * @param array<string, true> $visited Common Events already followed.
   * @return list<string>
   */
  private static function findTransferDestinations(mixed $node, string $assetRoot, array &$visited, int $depth = 0): array
  {
    if (!is_array($node) || $depth > self::MAX_SCRIPT_DEPTH * 4) { return []; }
    $found = [];
    $type = $node['type'] ?? null;
    if ($type === 'transfer' && is_string($node['map'] ?? null) && trim($node['map']) !== '') {
      $found[] = trim($node['map']);
    }
    if ($type === 'common_event' && is_string($node['id'] ?? null) && !isset($visited[$node['id']])
      && count($visited) < self::MAX_SCRIPT_DEPTH) {
      $visited[$node['id']] = true;
      array_push($found, ...self::findTransferDestinations(self::readScript($assetRoot, $node['id']), $assetRoot, $visited, $depth + 1));
    }
    foreach ($node as $child) {
      if (is_array($child)) { array_push($found, ...self::findTransferDestinations($child, $assetRoot, $visited, $depth + 1)); }
    }
    return $found;
  }

  private static function readScript(string $assetRoot, string $scriptId): mixed
  {
    if (preg_match('/^[a-zA-Z0-9._-]+$/', $scriptId) !== 1) { return null; }
    $filename = $assetRoot . '/Events/' . $scriptId . '.php';
    if (!is_file($filename)) { return null; }
    try {
      return (static fn(string $file): mixed => require $file)($filename);
    } catch (Throwable $error) {
      Debug::warn(sprintf('Event script %s is unreadable for routes: %s', $scriptId, $error->getMessage()));
      return null;
    }
  }
}
