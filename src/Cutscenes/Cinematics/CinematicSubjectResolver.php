<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** Resolves generic cinematic subject references against the live field. */
final class CinematicSubjectResolver
{
  public function __construct(protected GameScene $gameScene)
  {
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
      'staged_actor' => $this->copy(
        $this->gameScene->cinematicStage?->find($id)?->position,
        sprintf('Staged actor "%s" was not found.', $id),
      ),
      'party_actor' => $this->partyActorPosition($id),
      'position' => new Vector2(intval($reference['x'] ?? 0), intval($reference['y'] ?? 0)),
      'marker' => $this->copy(
        $this->gameScene->player?->findEventMarkerPosition($id),
        sprintf('Map marker "%s" was not found.', $id),
      ),
      default => throw new RuntimeException(sprintf('Unsupported cinematic subject kind "%s".', $kind ?: '(empty)')),
    };
  }

  protected function partyActorPosition(string $id): Vector2
  {
    $members = $this->gameScene->party?->members->toArray() ?? [];

    foreach ($members as $index => $actor) {
      $matches = $id === ''
        ? $index === 0
        : in_array($id, array_filter([
          property_exists($actor, 'id') ? strval($actor->id) : null,
          property_exists($actor, 'name') ? strval($actor->name) : null,
        ]), true);

      if (! $matches) {
        continue;
      }

      if ($index === 0 && $this->gameScene->player !== null) {
        return $this->copy($this->gameScene->player->position, 'Player field representation is not available.');
      }

      $npc = $this->gameScene->npcManager?->findById($id);

      if ($npc !== null) {
        return $this->copy($npc->position, 'Party actor field representation is not available.');
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
