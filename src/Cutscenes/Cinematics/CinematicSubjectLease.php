<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;

/** A real subject's temporary transform, independent of its replaceable artwork. */
final class CinematicSubjectLease
{
  private bool $released = false;
  private Vector2 $entryPosition;
  private MovementHeading $entryHeading;
  private array|string $entrySprite;
  private readonly string $mapId;

  public function __construct(
    private readonly GameScene $scene,
    public readonly Player|Npc $subject,
  ) {
    $this->mapId = $scene->currentMapId;
    $this->commit();
  }

  public function isCurrent(): bool
  {
    return !$this->released && $this->scene->currentMapId === $this->mapId
      && ($this->subject instanceof Player
        ? $this->scene->player === $this->subject
        : $this->scene->npcManager?->findById($this->subject->id ?? '') === $this->subject);
  }

  public function isEligible(): bool
  {
    return $this->isCurrent() && ($this->subject instanceof Player
      || in_array($this->subject, $this->scene->npcManager?->visibleNpcs() ?? [], true));
  }

  /** Ratify only this transform, never story state or presentation eligibility. */
  public function commit(): void
  {
    if (!$this->isCurrent()) {
      return;
    }
    $this->entryPosition = clone $this->subject->position;
    $this->entryHeading = $this->subject->heading;
    $this->entrySprite = $this->subject->sprite;
  }

  public function restore(): void
  {
    if (!$this->isEligible()) {
      return;
    }
    if ($this->subject instanceof Player) {
      $this->subject->restoreFieldTransform($this->entryPosition, $this->entryHeading, (array)$this->entrySprite);
    } else {
      $this->subject->restoreFieldTransform($this->entryPosition, $this->entryHeading, strval($this->entrySprite));
    }
  }

  public function release(bool $restore): void
  {
    if ($restore) {
      $this->restore();
    }
    $this->released = true;
  }
}
