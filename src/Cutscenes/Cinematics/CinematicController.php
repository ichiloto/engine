<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\EngineStoryEvent;
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
  private ?CinematicConversation $conversation = null;
  private ?CinematicFramingDefinition $framingDefinition = null;
  private ?CinematicFramingScope $framingScope = null;
  /** The active conversation phase, or the cinematic when no conversation is declared. */
  public ?object $dialogueFramingScope { get => $this->getCurrentFramingScope() ?? $this->conversation?->framingScope ?? $this->active; }

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
      $this->conversation = isset($cinematic->presentation['conversation'])
        ? new CinematicConversation($this->gameScene, $cinematic->presentation['conversation']) : null;
      $this->framingDefinition = new CinematicFramingDefinition($cinematic->presentation['framing'] ?? []);
      $initialPresentation = strtolower(trim(strval($cinematic->presentation['initial'] ?? '')));

      if (in_array($initialPresentation, ['black', 'hidden'], true)) {
        $this->gameScene->cinematicPresentation?->hideField();
      }

      $staged = array_values(array_filter(
        $cinematic->cast,
        static fn(array $entry): bool =>
          strtolower(strval($entry['kind'] ?? 'staged_actor')) === 'staged_actor'
      ));
      $this->gameScene->cinematicStage?->configure($staged);
      $this->gameScene->observeDialogueFramingSubjects();
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

  /** Both cooperative text commands and ordinary dialogue pages use the active conversation's bindings. */
  public function prepareDialogue(string $speaker, array $line = []): void
  {
    $this->conversation?->prepareLine($speaker, $line);
  }

  /** Current conversation subjects and visible staged actors share real subject identity. */
  public function getFramingSubjects(): array
  {
    if ($this->active === null) { return []; }
    if (($scope = $this->getCurrentFramingScope()) !== null) {
      return $this->resolveFramingSubjects($scope->references);
    }
    $subjects = $this->conversation?->getFramingSubjects() ?? [];
    if ($this->gameScene->player !== null && !($this->gameScene->cinematicStage?->suppresses($this->gameScene->player) ?? false)) {
      $subjects[] = $this->gameScene->player;
    }
    foreach ($this->gameScene->cinematicStage?->all() ?? [] as $actor) {
      if ($actor->isVisible && !($actor->subject?->subject instanceof \Ichiloto\Engine\Field\WorldObject)) { $subjects[] = $actor; }
    }
    $unique = [];
    foreach ($subjects as $subject) {
      if ($subject instanceof StagedActor && !$subject->isVisible) { continue; }
      if ($subject instanceof \Ichiloto\Engine\Field\Npc
        && !($this->gameScene->npcManager?->canStageNpc($subject) ?? false)) { continue; }
      $real = $subject instanceof StagedActor ? ($subject->subject?->subject ?? $subject) : $subject;
      $unique[spl_object_id($real)] = $subject;
    }
    return array_values($unique);
  }

  /** Resolve every reference and reject conflicting lanes before changing the current phase. */
  public function beginFramingPhase(array $requirements, string $lanePath): CinematicFramingScope
  {
    if ($this->active === null || $this->framingDefinition === null) {
      throw new RuntimeException('A framing phase requires an active cinematic.');
    }
    $references = $this->framingDefinition->getSubjectReferences($requirements);
    $this->resolveFramingSubjects($references);
    $parent = $this->getCurrentFramingScope();
    if ($parent !== null && $lanePath !== $parent->lanePath
      && !str_starts_with($lanePath, $parent->lanePath . '/')) {
      throw new RuntimeException('Concurrent sibling lanes cannot replace another lane framing phase.');
    }
    return $this->framingScope = new CinematicFramingScope($references, $lanePath,
      $this->gameScene->currentMapId, $this->gameScene->cinematicStage?->generation, $parent);
  }

  public function endFramingPhase(CinematicFramingScope $scope): void
  {
    if ($this->getCurrentFramingScope() === $scope) { $this->framingScope = $scope->parent; }
  }

  public function getCurrentFramingScope(): ?CinematicFramingScope
  {
    if ($this->framingScope !== null && ($this->framingScope->mapId !== $this->gameScene->currentMapId
      || $this->framingScope->stageGeneration !== $this->gameScene->cinematicStage?->generation)) {
      $this->framingScope = null;
    }
    return $this->framingScope;
  }

  private function resolveFramingSubjects(array $references): array
  {
    $resolver = new CinematicSubjectResolver($this->gameScene);
    $subjects = $identities = [];
    foreach ($references as $reference) {
      try { $subject = $resolver->getSpriteSubject($reference); }
      catch (\RuntimeException $error) {
        throw new \RuntimeException('Required framing subject: ' . $error->getMessage(), previous: $error);
      }
      if ($subject instanceof \Ichiloto\Engine\Field\Player || $subject instanceof \Ichiloto\Engine\Field\Npc) {
        $visual = $this->gameScene->cinematicStage?->findSubjectVisual($subject);
        if ($visual !== null) { $subject = $visual; }
        elseif ($this->gameScene->cinematicStage?->suppresses($subject) ?? false) {
          throw new RuntimeException('A required subject is suppressed without a visible field representation.');
        }
      }
      $real = $subject instanceof StagedActor ? ($subject->subject?->subject ?? $subject) : $subject;
      $id = spl_object_id($real);
      if (isset($identities[$id])) { throw new RuntimeException('Required framing references select one real subject twice.'); }
      $identities[$id] = true; $subjects[] = $subject;
    }
    return $subjects;
  }

  public function selectConversationGroup(string $id): void
  {
    ($this->conversation ?? throw new RuntimeException('No cinematic conversation is active.'))->selectGroup($id);
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

    $gameScene->gameState->recordStoryEvent(EngineStoryEvent::CINEMATIC_COMPLETED->getName($this->active->id));
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
      try {
        $this->cleanup();
      } finally {
        // Release the trigger even if presentation teardown raised an error.
        $downstreamCompletionTarget?->onEventSessionFailed($gameScene, $session);
      }
    }
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
    // Restore the logical camera first, then release the projection towards its final target.
    $this->gameScene->clearDialogueFraming();

    $this->active = null;
    $this->conversation = null;
    $this->framingDefinition = null;
    $this->framingScope = null;
    $this->cameraBefore = null;
    $this->cameraMapBefore = null;
    $this->downstreamCompletionTarget = null;
  }
}
