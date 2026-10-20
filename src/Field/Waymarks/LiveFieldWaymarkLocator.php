<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Locates waymark subjects in the running field: events through the
 * player's loaded triggers, NPCs where their conditions place them now,
 * world objects by id, and landmarks only once the player may see them.
 */
final readonly class LiveFieldWaymarkLocator implements FieldWaymarkLocatorInterface
{
  public function __construct(private GameScene $scene)
  {
  }

  public function locate(QuestWaymarkSubject $subject, string $id): array
  {
    return match ($subject) {
      QuestWaymarkSubject::EVENT => $this->locateEvent($id),
      QuestWaymarkSubject::NPC => $this->locateNpc($id),
      QuestWaymarkSubject::OBJECT => $this->locateObject($id),
      QuestWaymarkSubject::LANDMARK => $this->locateLandmark($id),
    };
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateEvent(string $marker): array
  {
    $event = $this->scene->player?->findEventByMarker($marker);
    if ($event === null || $event->isComplete) { return []; }
    return array_map(static fn(CellArea $piece) => new FieldWaymarkAnchor($piece->findCenterCell(), eventMarker: $marker),
      $event->area->findPieces());
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateNpc(string $id): array
  {
    $npc = $this->scene->npcManager?->findPresentById($id);
    return $npc === null ? [] : [new FieldWaymarkAnchor(clone $npc->position, $npc->getGraphicalSpriteId())];
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateObject(string $id): array
  {
    $object = $this->scene->mapManager?->findWorldObject($id);
    return $object === null ? [] : [new FieldWaymarkAnchor($object->position, $object->getGraphicalSpriteId())];
  }

  /** @return list<FieldWaymarkAnchor> */
  private function locateLandmark(string $id): array
  {
    $knowledge = $this->scene->mapManager?->mapKnowledge;
    if ($knowledge === null) { return []; }
    foreach ($knowledge->getVisibleLandmarks($this->scene->gameState, $this->scene->party) as $entry) {
      if ($entry['definition']->id === $id) {
        return array_map(static fn($cell) => new FieldWaymarkAnchor($cell), $entry['definition']->getMarkerCells());
      }
    }
    return [];
  }
}
