<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\WorldObject;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** A read-only subject binding scoped to the current map and cinematic generation. */
final readonly class CinematicEffectTarget
{
  public Player|Npc|WorldObject|StagedActor $subject;
  private string $mapId;
  private ?MapLayerSet $layers;
  private ?int $generation;
  private ?CinematicStageManager $stage;
  private ?MapManager $map;

  public function __construct(private GameScene $scene, private array $reference)
  {
    $this->subject = new CinematicSubjectResolver($scene)->getSpriteSubject($reference);
    $this->mapId = $scene->currentMapId;
    $this->layers = $scene->mapManager?->layers;
    $this->generation = $scene->cinematicStage?->generation;
    $this->stage = $scene->cinematicStage;
    $this->map = $scene->mapManager;
  }

  public function isCurrent(): bool
  {
    if ($this->scene->isStopping || $this->scene->currentMapId !== $this->mapId
      || $this->scene->mapManager !== $this->map || $this->scene->cinematicStage !== $this->stage
      || $this->scene->mapManager?->layers !== $this->layers
      || $this->scene->cinematicStage?->generation !== $this->generation) { return false; }
    try { return new CinematicSubjectResolver($this->scene)->getSpriteSubject($this->reference) === $this->subject; }
    catch (RuntimeException) { return false; }
  }

  public function getPosition(): Vector2
  {
    return clone $this->subject->position;
  }

  public function getGraphicalPosition(): Vector2
  {
    return clone $this->subject->getGraphicalSpriteWorldPosition();
  }
}
