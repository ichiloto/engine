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
  protected ?EventSessionCompletionTargetInterface $downstreamCompletionTarget = null;
  protected bool $terminalOutcomeHandled = false;
  private ?string $cameraMapBefore = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  public function active(): ?CinematicDefinition
  {
    return $this->active;
  }

  public function start(
    CinematicDefinition $cinematic,
    ?EventSessionCompletionTargetInterface $downstreamCompletionTarget = null,
  ): ?EventExecutionSession
  {
    if ($this->active !== null || $this->gameScene->hasUnstableEventSession()) {
      throw new RuntimeException('Only one field cinematic may own control at a time.');
    }

    $this->active = $cinematic;
    $this->cameraBefore = $this->gameScene->camera->captureState();
    $this->cameraMapBefore = $this->gameScene->currentMapId;
    $this->downstreamCompletionTarget = $downstreamCompletionTarget;
    $this->terminalOutcomeHandled = false;

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
    } elseif ($this->active !== null && $this->gameScene->sceneManager->currentScene === $this->gameScene) {
      // Apply an initial cover before yielding, but never paint a failed setup.
      $this->gameScene->restoreFieldAfterOverlay();
      $this->gameScene->cinematicPresentation?->render();
    }

    return $session;
  }

  public function skip(): bool
  {
    return $this->gameScene->eventInterpreter?->skipActiveCinematic() ?? false;
  }

  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    if ($this->active === null || $session->scriptId !== $this->active->id
      || $gameScene->eventInterpreter?->activeSession() !== $session) {
      throw new RuntimeException('Cinematic completion identity did not match the active asset.');
    }

    if ($this->terminalOutcomeHandled) {
      throw new RuntimeException('Cinematic terminal outcome was already handled.');
    }

    $this->terminalOutcomeHandled = true;
    $downstreamCompletionTarget = $this->downstreamCompletionTarget;

    $gameScene->gameState->recordStoryEvent('cinematic:' . $this->active->id . ':completed');
    try {
      $gameScene->getGame()->audioManager->finalizeCinematicMusic('complete');
    } finally {
      $this->cleanup(restoreTransforms: false);
    }
    $downstreamCompletionTarget?->onEventSessionCompleted($gameScene, $session);
  }

  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    if ($this->terminalOutcomeHandled || $this->active === null
      || $gameScene->eventInterpreter?->activeSession() !== $session) {
      return;
    }

    $this->terminalOutcomeHandled = true;
    $downstreamCompletionTarget = $this->downstreamCompletionTarget;
    try {
      $gameScene->getGame()->audioManager->finalizeCinematicMusic('failure');
    } finally {
      $this->cleanup();
    }
    $downstreamCompletionTarget?->onEventSessionFailed($gameScene, $session);
  }

  public function shutdown(): void
  {
    if ($this->active !== null) {
      $this->gameScene->eventInterpreter?->failActiveSession('Cinematic interrupted by field shutdown.');
      if ($this->active !== null) {
        $this->cleanup();
      }
    }
  }

  protected function cleanup(bool $restoreTransforms = true): void
  {
    $this->gameScene->cinematicStage?->clear($restoreTransforms);
    $this->gameScene->cinematicPresentation?->clear();

    if (($this->cameraBefore?->followsPlayer ?? true) || $this->cameraMapBefore !== $this->gameScene->currentMapId) {
      $this->gameScene->camera->attach($this->gameScene->player);
    } elseif ($this->cameraBefore !== null) {
      $this->gameScene->camera->restoreState($this->cameraBefore);
    }

    $this->active = null;
    $this->cameraBefore = null;
    $this->cameraMapBefore = null;
    $this->downstreamCompletionTarget = null;
  }
}
