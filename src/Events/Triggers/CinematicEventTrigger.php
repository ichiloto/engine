<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Actions\RunCinematicAction;
use Ichiloto\Engine\Events\Interfaces\AutomaticEventTriggerInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Launches one first-class Cinematic asset from authored map data.
 *
 * The CinematicController remains the EventInterpreter's direct completion
 * target. It chains the terminal result back to this trigger only after the
 * Cinematic has completed normally, completed through its legal authored
 * skip, or failed under the existing controlled-failure path.
 */
final class CinematicEventTrigger extends EventTrigger implements
  AutomaticEventTriggerInterface,
  EventSessionCompletionTargetInterface
{
  protected(set) ?string $cinematicId = null;
  protected(set) bool $runsAutomatically = false;
  protected(set) bool $sessionIsActive = false;
  protected ?string $configurationError = null;

  /** @inheritDoc */
  public function configure(): void
  {
    $this->isReusable = (bool) ($this->data->reusable ?? true);
    $mode = strtolower(trim(strval($this->data->mode ?? 'action')));
    $this->runsAutomatically = $mode === 'auto';

    if (! in_array($mode, CinematicCommandSchema::CINEMATIC_TRIGGER_MODES, true)) {
      $this->configurationError = sprintf('unsupported mode "%s"', $mode !== '' ? $mode : '(empty)');
    }

    $cinematicId = trim(strval($this->data->cinematicId ?? ''));
    $this->cinematicId = $cinematicId !== '' ? $cinematicId : null;

    if ($cinematicId === '') {
      $this->configurationError = 'cinematicId is required';
    } elseif (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $cinematicId) !== 1) {
      $this->configurationError = sprintf('cinematicId "%s" is not a stable lowercase identifier', $cinematicId);
    }
  }

  public function runsAutomatically(): bool
  {
    return $this->runsAutomatically;
  }

  /** Starts the referenced Cinematic when the field has no other owner. */
  public function startSession(GameScene $gameScene): ?EventExecutionSession
  {
    if ($this->sessionIsActive || $this->isComplete || ! $this->isAvailable()) {
      return null;
    }

    if ($this->configurationError !== null || $this->cinematicId === null) {
      Debug::error($this->diagnostic($this->configurationError ?? 'cinematicId is required'));
      return null;
    }

    if ($gameScene->hasUnstableEventSession() || $gameScene->cinematicController?->active() !== null) {
      Debug::warn($this->diagnostic('another field event or Cinematic already owns the session'));
      return null;
    }

    // Mark before launch because a state-only Cinematic may complete or fail
    // synchronously inside startCinematic(). Its callback must be allowed to
    // clear this flag without that result being overwritten afterward.
    $this->sessionIsActive = true;

    try {
      $session = $gameScene->startCinematic($this->cinematicId, $this);
    } catch (Throwable $throwable) {
      $this->sessionIsActive = false;
      Debug::error($this->diagnostic($throwable->getMessage()));
      return null;
    }

    if ($session === null) {
      $this->sessionIsActive = false;
      Debug::warn($this->diagnostic('the Cinematic launch was refused'));
    }

    return $session;
  }

  /** @inheritDoc */
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->sessionIsActive = false;
    $this->complete();
  }

  /** @inheritDoc */
  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->sessionIsActive = false;
  }

  /** @inheritDoc */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);

    if ($this->runsAutomatically) {
      (new RunCinematicAction($this))->execute(new FieldActionContext(
        $context->player,
        $context->scene,
        $context->player->position,
      ));
      return;
    }

    $context->player->erase();
    $context->player->availableAction = new RunCinematicAction($this);
    $context->player->render();
  }

  /** @inheritDoc */
  public function exit(EventTriggerContextInterface $context): void
  {
    parent::exit($context);

    if (! $this->runsAutomatically) {
      $context->player->erase();
      $context->player->availableAction = null;
      $context->player->render();
    }
  }

  protected function diagnostic(string $reason): string
  {
    return sprintf(
      'Cinematic trigger failed closed on map "%s" marker "%s" for asset "%s": %s.',
      $this->mapId ?? '(unknown)',
      $this->marker ?? '(unknown)',
      $this->cinematicId ?? '(missing)',
      trim($reason),
    );
  }
}
