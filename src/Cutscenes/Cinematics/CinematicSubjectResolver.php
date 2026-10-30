<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Field\WorldObject;
use RuntimeException;
use InvalidArgumentException;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;

/** Resolves generic cinematic subject references against the live field. */
final class CinematicSubjectResolver
{
  public function __construct(protected GameScene $gameScene)
  {
  }

  /** The shared authored reference boundary used by cinematics, routes and staged locations. */
  public static function validateReference(array $reference, bool $allowScreenPosition = false): void
  {
    $kind = strtolower(trim(strval($reference['kind'] ?? $reference['subject'] ?? '')));
    $allowed = $allowScreenPosition ? CinematicCommandSchema::SUBJECT_KINDS
      : array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, ['screen_position']));
    if (!in_array($kind, $allowed, true)) {
      throw new InvalidArgumentException(sprintf('unsupported subject kind "%s".', $kind !== '' ? $kind : '(empty)'));
    }
    if (in_array($kind, ['npc', 'world_object', 'staged_actor', 'marker'], true)
      && trim(strval($reference['id'] ?? '')) === '') {
      throw new InvalidArgumentException(sprintf('%s subject requires an id.', $kind));
    }
    if (in_array($kind, ['position', 'screen_position'], true)
      && (!is_numeric($reference['x'] ?? null) || !is_numeric($reference['y'] ?? null))) {
      throw new InvalidArgumentException(sprintf('%s subject requires numeric x and y coordinates.', $kind));
    }
  }

  /** @param array<string, mixed> $reference */
  public function position(array $reference): Vector2
  {
    $kind = strtolower(trim(strval($reference['kind'] ?? $reference['subject'] ?? '')));
    $id = trim(strval($reference['id'] ?? ''));

    return match ($kind) {
      'player' => $this->copy($this->gameScene->player?->position, 'Player is not available.'),
      'npc' => $this->copy(
        $this->gameScene->npcManager?->findById($id)?->position,
        sprintf('Map NPC "%s" was not found.', $id),
      ),
      'world_object' => $this->getWorldObjectPosition($id),
      'staged_actor' => $this->copy(
        $this->gameScene->cinematicStage?->find($id)?->position,
        sprintf('Staged actor "%s" was not found.', $id),
      ),
      'party_actor' => $this->getPartyActorPosition($id),
      'position' => new Vector2(intval($reference['x'] ?? 0), intval($reference['y'] ?? 0)),
      'marker' => $this->copy(
        $this->gameScene->player?->findEventMarkerPosition($id),
        sprintf('Map marker "%s" was not found.', $id),
      ),
      default => throw new RuntimeException(sprintf('Unsupported cinematic subject kind "%s".', $kind ?: '(empty)')),
    };
  }

  /** A live field identity for effects that explicitly follow their selected subject. */
  public function getSpriteSubject(array $reference): Player|Npc|WorldObject|StagedActor
  {
    self::validateReference($reference);
    $kind = strtolower(trim(strval($reference['kind'] ?? $reference['subject'] ?? '')));
    $id = trim(strval($reference['id'] ?? ''));
    $subject = match ($kind) {
      'player' => $this->gameScene->player,
      'party_actor' => $this->getPartyActorSubject($id),
      'npc' => $this->gameScene->npcManager?->findPresentById($id),
      'world_object' => $this->findWorldObject($id),
      'staged_actor' => $this->gameScene->cinematicStage?->find($id),
      default => throw new RuntimeException('Following effects require a live field subject.'),
    };
    if ($subject === null || ($subject instanceof StagedActor && !$subject->isVisible)
      || ($subject instanceof Npc && $this->gameScene->npcManager?->findPresentById($subject->id ?? '') !== $subject)) {
      throw new RuntimeException('The selected effect subject is not present.');
    }
    return $subject;
  }

  private function getWorldObjectPosition(string $id): Vector2
  {
    return $this->findWorldObject($id)->position;
  }

  /** A current world object's named attachment, which its resource declares. */
  public function attachment(string $objectId, string $attachmentId): CinematicAttachment
  {
    $object = $this->findWorldObject($objectId);
    $attachment = $object->definition->resource?->findAttachment($attachmentId);
    if ($attachment === null) {
      throw new RuntimeException(sprintf('Map world object "%s" has no attachment "%s".', $objectId, $attachmentId));
    }
    return new CinematicAttachment($object, $attachment);
  }

  private function findWorldObject(string $id): WorldObject
  {
    $object = $this->gameScene->mapManager?->findWorldObject($id);
    if ($object === null || !$object->isCurrent()) {
      throw new RuntimeException(sprintf('Map world object "%s" was not found.', $id));
    }
    return $object;
  }

  protected function getPartyActorPosition(string $id): Vector2
  {
    return $this->copy($this->getPartyActorSubject($id)->position, 'Party actor field representation is not available.');
  }

  protected function getPartyActorSubject(string $id): Player|Npc
  {
    $members = $this->gameScene->party?->members->toArray() ?? [];
    $hasStableIdentity = $id !== '' && array_any($members, static fn($actor): bool => $actor->actorId === $id);

    foreach ($members as $actor) {
      $matches = $id === ''
        ? $actor === $this->gameScene->party?->leader
        : ($hasStableIdentity ? $actor->actorId === $id : in_array($id, array_filter([
          property_exists($actor, 'id') ? strval($actor->id) : null,
          property_exists($actor, 'name') ? strval($actor->name) : null,
        ]), true));

      if (! $matches) {
        continue;
      }

      if ($actor === $this->gameScene->party?->leader && $this->gameScene->player !== null) {
        return $this->gameScene->player;
      }

      $npc = $this->gameScene->npcManager?->findById($id);

      if ($npc !== null) {
        return $npc;
      }

      throw new RuntimeException(sprintf('Party actor "%s" has no field representation.', $id));
    }

    throw new RuntimeException(sprintf('Party actor "%s" was not found.', $id));
  }

  protected function copy(?Vector2 $position, string $failure): Vector2
  {
    if ($position === null) {
      throw new RuntimeException($failure);
    }

    return new Vector2(intval($position->x), intval($position->y));
  }
}
