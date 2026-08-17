<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;

/** The explicit, non-serializable continuation for one event script. */
final class EventExecutionSession
{
  protected static int $nextId = 0;
  protected EventExecutionLane $rootLane;
  protected ?EventExecutionLane $suspendedLane = null;
  protected ?string $presentationOwner = null;

  protected(set) EventExecutionStatus $status = EventExecutionStatus::RUNNING;
  protected(set) ?string $failureMessage = null;
  protected(set) array $origin = [];
  protected(set) array $checkpoints = [];
  protected(set) bool $completionWasClaimed = false;
  protected(set) bool $isFinalizing = false;
  protected(set) ?CinematicDefinition $cinematic = null;

  public ?array $pendingCommand {
    get => ($this->suspendedLane ?? $this->rootLane)->pendingCommand;
  }

  public array $pendingState {
    get => ($this->suspendedLane ?? $this->rootLane)->pendingState;
  }

  public function __construct(
    array $commands,
    protected(set) ?string $scriptId = null,
    protected(set) ?EventSessionCompletionTargetInterface $completionTarget = null,
    array $origin = [],
  )
  {
    $this->id = ++self::$nextId;
    $this->origin = $origin;
    $this->rootLane = new EventExecutionLane($commands, 'root', $scriptId ?? 'inline script');
  }

  protected(set) int $id;

  public function rootLane(): EventExecutionLane
  {
    return $this->rootLane;
  }

  /** @return EventExecutionFrame[] */
  public function frames(): array
  {
    return $this->rootLane->frames();
  }

  public function currentFrame(): ?EventExecutionFrame
  {
    return $this->rootLane->currentFrame();
  }

  public function currentCommand(): ?array
  {
    return $this->rootLane->currentCommand();
  }

  public function advance(): void
  {
    $this->rootLane->advance();
  }

  public function pushFrame(array $commands, string $label): void
  {
    $this->rootLane->pushFrame($commands, $label);
  }

  public function yieldFor(array $command, array $state = []): void
  {
    $this->rootLane->yieldFor($command, $state);
    $this->refreshStatus();
  }

  public function suspendFor(array $command, array $state = []): void
  {
    $this->suspendLane($this->rootLane, $command, $state);
  }

  public function suspendLane(EventExecutionLane $lane, array $command, array $state = []): void
  {
    if ($this->suspendedLane !== null && $this->suspendedLane !== $lane) {
      throw new \RuntimeException('An event session cannot suspend two lanes at once.');
    }

    $lane->suspendFor($command, $state);
    $this->suspendedLane = $lane;
    $this->status = EventExecutionStatus::SUSPENDED;
  }

  public function resumePendingCommand(): void
  {
    if ($this->suspendedLane !== null) {
      $this->status = EventExecutionStatus::YIELDED;
    }
  }

  public function completePendingCommand(): void
  {
    $lane = $this->suspendedLane ?? $this->rootLane;
    $lane->completePendingCommand();
    $this->suspendedLane = null;
    $this->status = EventExecutionStatus::RUNNING;
    $this->refreshStatus();
  }

  public function updatePendingState(array $state): void
  {
    ($this->suspendedLane ?? $this->rootLane)->updatePendingState($state);
  }

  public function complete(): void
  {
    $this->rootLane->complete();
    $this->status = EventExecutionStatus::COMPLETED;
  }

  public function fail(string $message): void
  {
    $this->failureMessage = $message;
    $this->rootLane->fail($message);
    $this->status = EventExecutionStatus::FAILED;
  }

  public function cancelLanes(): void
  {
    $this->rootLane->cancel();
    $this->suspendedLane = null;
    $this->presentationOwner = null;
  }

  public function hasFinishedFrames(): bool
  {
    return $this->rootLane->hasFinishedFrames();
  }

  public function claimPresentation(EventExecutionLane $lane): void
  {
    if ($this->presentationOwner !== null && $this->presentationOwner !== $lane->path) {
      throw new \RuntimeException(sprintf(
        'Presentation is already owned by lane "%s"; lane "%s" cannot open another modal.',
        $this->presentationOwner,
        $lane->path,
      ));
    }

    $this->presentationOwner = $lane->path;
  }

  public function releasePresentation(EventExecutionLane $lane): void
  {
    if ($this->presentationOwner === $lane->path) {
      $this->presentationOwner = null;
    }
  }

  public function recordCheckpoint(string $name): void
  {
    $name = trim($name);

    if ($name !== '' && ! in_array($name, $this->checkpoints, true)) {
      $this->checkpoints[] = $name;
    }
  }

  public function claimCompletion(): bool
  {
    if ($this->completionWasClaimed) {
      return false;
    }

    $this->completionWasClaimed = true;
    return true;
  }

  public function beginFinalizer(): void
  {
    $this->isFinalizing = true;
  }

  public function configureCinematic(CinematicDefinition $cinematic): void
  {
    $this->cinematic = $cinematic;
  }

  /** Starts the always-run finalizer once, returning whether it was started. */
  public function startFinalizerIfNeeded(): bool
  {
    if ($this->cinematic === null || $this->isFinalizing || $this->cinematic->finalizer === []) {
      return false;
    }

    $this->cancelLanes();
    $this->isFinalizing = true;
    $this->status = EventExecutionStatus::RUNNING;
    $this->rootLane = new EventExecutionLane(
      $this->cinematic->finalizer,
      'root/finalizer',
      sprintf('cinematic:%s:finalizer', $this->cinematic->id),
    );
    return true;
  }

  public function refreshStatus(): void
  {
    if (in_array($this->status, [EventExecutionStatus::COMPLETED, EventExecutionStatus::FAILED, EventExecutionStatus::SUSPENDED], true)) {
      return;
    }

    $this->status = $this->rootLane->status === EventExecutionStatus::YIELDED
      ? EventExecutionStatus::YIELDED
      : EventExecutionStatus::RUNNING;
  }
}
