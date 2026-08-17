<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Rendering\CameraStateSnapshot;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** Hosts a cinematic on the GameScene-owned EventInterpreter. */
final class CinematicController implements EventSessionCompletionTargetInterface
{
  protected ?CinematicDefinition $active = null;
  protected ?CameraStateSnapshot $cameraBefore = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  public function active(): ?CinematicDefinition
  {
    return $this->active;
  }

  public function start(CinematicDefinition $cinematic): ?EventExecutionSession
  {
    if ($this->active !== null || $this->gameScene->hasUnstableEventSession()) {
      throw new RuntimeException('Only one field cinematic may own control at a time.');
    }

    $this->active = $cinematic;
    $this->cameraBefore = $this->gameScene->camera->captureState();

    try {
      $initialPresentation = strtolower(trim(strval($cinematic->presentation['initial'] ?? '')));

      if (in_array($initialPresentation, ['black', 'hidden'], true)) {
        $this->gameScene->cinematicPresentation?->hideField();
      }

      $staged = array_values(array_filter(
        $cinematic->cast,
        static fn(array $entry): bool =>
          strtolower(strval($entry['kind'] ?? 'staged_actor')) === 'staged_actor'
          && (isset($entry['sprite']) || isset($entry['asset']))
      ));
      $this->gameScene->cinematicStage?->configure($staged);
      $session = $this->gameScene->eventInterpreter?->runCinematic($cinematic, $this);
    } catch (\Throwable $throwable) {
      $this->cleanup();
      throw $throwable;
    }

    if ($session === null) {
      $this->cleanup();
    }

    return $session;
  }

  public function skip(): bool
  {
    return $this->gameScene->eventInterpreter?->skipActiveCinematic() ?? false;
  }

  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    if ($this->active === null || $session->scriptId !== $this->active->id) {
      throw new RuntimeException('Cinematic completion identity did not match the active asset.');
    }

    $gameScene->gameState->recordStoryEvent('cinematic:' . $this->active->id . ':completed');
    $gameScene->getGame()->audioManager->finalizeCinematicMusic('complete');
    $this->cleanup();
  }

  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $gameScene->getGame()->audioManager->finalizeCinematicMusic('failure');
    $this->cleanup();
  }

  protected function cleanup(): void
  {
    $this->gameScene->cinematicStage?->clear();
    $this->gameScene->cinematicPresentation?->clear();

    if ($this->cameraBefore?->followsPlayer ?? true) {
      $this->gameScene->camera->attach($this->gameScene->player);
    } elseif ($this->cameraBefore !== null) {
      $this->gameScene->camera->restoreState($this->cameraBefore);
    }

    $this->active = null;
    $this->cameraBefore = null;
  }
}
