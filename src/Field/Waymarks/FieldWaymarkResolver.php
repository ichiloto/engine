<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Closure;
use Ichiloto\Engine\Field\MapRoute;
use Ichiloto\Engine\Field\MapRouteGraph;
use Ichiloto\Engine\Quests\ActiveQuestWaymark;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;

/**
 * Turns the active quest waymarks into points on the active map.
 *
 * A waymark on this map marks its subject. A waymark on another map marks
 * the first usable exit of the cheapest route toward it. Several waymarks
 * leading through the same exit share one route mark, and an exit that is
 * itself a target is marked once, as the target.
 */
final class FieldWaymarkResolver
{
  /**
   * @param list<ActiveQuestWaymark> $active In journal order.
   * @param Closure(MapRoute): bool $canUseRoute Whether a route is usable now.
   * @return list<FieldWaymark>
   */
  public static function resolve(array $active, string $mapId, FieldWaymarkLocatorInterface $locator,
    ?MapRouteGraph $routes, Closure $canUseRoute): array
  {
    $targets = [];
    $seen = [];
    $routeMarks = [];
    $nextRoutes = [];
    foreach ($active as $entry) {
      $waymark = $entry->waymark;
      if ($waymark->map === $mapId) {
        if ($waymark->subject === null) { continue; }
        foreach ($locator->locate($waymark->subject, $waymark->subjectId) as $index => $anchor) {
          // Two objectives sending the player to one subject share its mark.
          $key = $anchor->objectId ?? ($anchor->cell->x . ':' . $anchor->cell->y);
          if (isset($seen[$key])) { continue; }
          $seen[$key] = true;
          $targets[] = new FieldWaymark('target:' . $entry->getId() . ':' . $index, FieldWaymarkRole::TARGET,
            $anchor, $entry->label);
        }
        continue;
      }
      if ($routes === null) { continue; }
      if (!array_key_exists($waymark->map, $nextRoutes)) {
        $nextRoutes[$waymark->map] = $routes->findNextRoute($mapId, $waymark->map, $canUseRoute);
      }
      $route = $nextRoutes[$waymark->map];
      if ($route === null || isset($routeMarks[$route->marker])) { continue; }
      if ($route->npcId !== null) {
        // Talking to the NPC is the way on: mark the NPC where it stands now.
        $routeMarks[$route->marker] = array_map(static fn(FieldWaymarkAnchor $anchor) => new FieldWaymark(
          'route:' . $mapId . ':' . $route->marker, FieldWaymarkRole::ROUTE, $anchor, $entry->label, $route->destination),
          $locator->locate(QuestWaymarkSubject::NPC, $route->npcId));
        continue;
      }
      $routeMarks[$route->marker] = array_map(
        static fn($piece) => new FieldWaymark('route:' . $mapId . ':' . $route->marker, FieldWaymarkRole::ROUTE,
          new FieldWaymarkAnchor($piece->findCenterCell(), eventMarker: str_starts_with($route->marker, '@') ? null : $route->marker),
          $entry->label, $route->destination),
        $route->area->findPieces(),
      );
    }

    $targetMarkers = [];
    foreach ($targets as $target) {
      if ($target->anchor->eventMarker !== null) { $targetMarkers[$target->anchor->eventMarker] = true; }
    }
    $resolved = $targets;
    foreach ($routeMarks as $marker => $marks) {
      if (isset($targetMarkers[$marker])) { continue; }
      foreach ($marks as $index => $mark) {
        $resolved[] = $index === 0 ? $mark : new FieldWaymark($mark->id . ':' . $index, $mark->role, $mark->anchor,
          $mark->label, $mark->destination);
      }
    }
    return $resolved;
  }
}
