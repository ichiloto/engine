<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Closure;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\MapAtlasDocument;
use Ichiloto\Engine\Field\MapKnowledge;
use Ichiloto\Engine\Quests\ActiveQuestWaymark;
use Throwable;

/**
 * Marks objectives on a world (global) atlas: subjects that lie on the
 * world map itself, and places the world map enters through for objectives
 * inside them. A place is marked only while its landmark is visible, so the
 * world map never reveals an undiscovered location.
 */
final class MapAtlasWaymarks
{
  /**
   * @param list<ActiveQuestWaymark> $active
   * @param Closure(string): (array{map: string, landmark: ?string}|null) $resolveGlobal The world map a map belongs to.
   * @return list<FieldWaymark>
   */
  public static function find(MapAtlasDocument $document, array $active, Closure $resolveGlobal, GameState $state,
    ?Party $party = null): array
  {
    $locator = new SourceFieldWaymarkLocator($document, $state, $party);
    $visible = [];
    foreach ((new MapKnowledge($document->id, $document->landmarks))->getVisibleLandmarks($state, $party) as $entry) {
      $visible[$entry['definition']->id] = $entry['definition'];
    }
    $bindings = [];
    $marks = [];
    $taken = [];
    foreach ($active as $entry) {
      $waymark = $entry->waymark;
      if ($waymark->map === $document->id) {
        $anchors = $waymark->subject === null ? [] : $locator->locate($waymark->subject, $waymark->subjectId);
      } else {
        if (!array_key_exists($waymark->map, $bindings)) {
          try { $bindings[$waymark->map] = $resolveGlobal($waymark->map); }
          catch (Throwable) { $bindings[$waymark->map] = null; }
        }
        $binding = $bindings[$waymark->map];
        $landmark = $binding !== null && $binding['map'] === $document->id && $binding['landmark'] !== null
          ? ($visible[$binding['landmark']] ?? null) : null;
        $anchors = $landmark === null ? [] : array_map(static fn($cell) => new FieldWaymarkAnchor($cell), $landmark->getMarkerCells());
      }
      foreach ($anchors as $index => $anchor) {
        $key = $anchor->cell->x . ':' . $anchor->cell->y;
        if (isset($taken[$key])) { continue; }
        $taken[$key] = true;
        $marks[] = new FieldWaymark('atlas:' . $entry->getId() . ':' . $index, FieldWaymarkRole::TARGET, $anchor, $entry->label);
      }
    }
    return $marks;
  }
}
