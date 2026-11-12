<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\MapAtlasDocument;
use Ichiloto\Engine\Field\MapKnowledge;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;

/**
 * Locates waymark subjects in a map that is not installed, from its source:
 * event areas, authored NPC placements, world objects, and landmarks the
 * player may already see. Nothing here discovers or activates the map.
 */
final readonly class SourceFieldWaymarkLocator implements FieldWaymarkLocatorInterface
{
  public function __construct(private MapAtlasDocument $document, private GameState $state, private ?Party $party = null)
  {
  }

  public function locate(QuestWaymarkSubject $subject, string $id): array
  {
    $data = $this->document->source['data'] ?? [];
    return match ($subject) {
      QuestWaymarkSubject::EVENT => $this->locateEvent($data, $id),
      QuestWaymarkSubject::NPC => $this->locateNpc($data, $id),
      QuestWaymarkSubject::OBJECT => $this->locateObject($id),
      QuestWaymarkSubject::LANDMARK => $this->locateLandmark($id),
    };
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateEvent(array $data, string $marker): array
  {
    foreach ($data['events'] ?? [] as $event) {
      if (($event['marker'] ?? null) !== $marker || !is_array($event['area'] ?? null)) { continue; }
      return array_map(static fn(CellArea $piece) => new FieldWaymarkAnchor($piece->findCenterCell(), eventMarker: $marker),
        CellArea::fromArray($event['area'])->findPieces());
    }
    return [];
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateNpc(array $data, string $id): array
  {
    foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $entry) {
      $placement = NpcPlacement::fromArray($entry);
      if ($placement?->id === $id) { return array_map(static fn(array $cell): FieldWaymarkAnchor => new FieldWaymarkAnchor(new Vector2(...$cell)), $placement->getStandingCells()); }
    }
    return [];
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateObject(string $id): array
  {
    foreach ($this->document->source['worldObjects'] ?? [] as $object) {
      if ($object->id === $id) { return [new FieldWaymarkAnchor(new Vector2($object->x, $object->y))]; }
    }
    return [];
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateLandmark(string $id): array
  {
    foreach ((new MapKnowledge($this->document->id, $this->document->landmarks))->getVisibleLandmarks($this->state, $this->party) as $entry) {
      if ($entry['definition']->id === $id) {
        return array_map(static fn(Vector2 $cell) => new FieldWaymarkAnchor($cell), $entry['definition']->getMarkerCells());
      }
    }
    return [];
  }
}
