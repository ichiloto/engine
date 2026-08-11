<?php

namespace Ichiloto\Engine\Events\Interpreter;

/**
 * The explicit, in-memory continuation for one event script.
 *
 * Sessions deliberately contain no serialized callback or save payload.
 * Save surfaces reject an active session and return to the last stable save.
 */
final class EventExecutionSession
{
  protected static int $nextId = 0;

  /** @var EventExecutionFrame[] The nested command frames. */
  protected array $frames = [];

  /** @var array<string, mixed>|null The command currently waiting or suspended. */
  protected(set) ?array $pendingCommand = null;

  /** @var array<string, mixed> Plain diagnostic/runtime state for the pending command. */
  protected(set) array $pendingState = [];

  protected(set) EventExecutionStatus $status = EventExecutionStatus::RUNNING;
  protected(set) ?string $failureMessage = null;

  /** @var array<string, scalar|null> Plain authoring origin used in diagnostics. */
  protected(set) array $origin = [];

  /**
   * @param array<int, array<string, mixed>> $commands The root command list.
   * @param string|null $scriptId Stable script identity when one exists.
   * @param EventSessionCompletionTargetInterface|null $completionTarget The
   * trigger or NPC whose completion work runs after the final command.
   * @param array<string, scalar|null> $origin Plain authoring origin metadata.
   */
  public function __construct(
    array $commands,
    protected(set) ?string $scriptId = null,
    protected(set) ?EventSessionCompletionTargetInterface $completionTarget = null,
    array $origin = [],
  )
  {
    $this->id = ++self::$nextId;
    $this->origin = $origin;
    $this->frames[] = new EventExecutionFrame($commands, $scriptId ?? 'inline script');
  }

  protected(set) int $id;

  /** @return EventExecutionFrame[] The current frame stack. */
  public function frames(): array
  {
    return $this->frames;
  }

  public function currentFrame(): ?EventExecutionFrame
  {
    $this->discardCompletedFrames();

    return $this->frames[array_key_last($this->frames)] ?? null;
  }

  /** @return array<string, mixed>|null The current command. */
  public function currentCommand(): ?array
  {
    return $this->currentFrame()?->currentCommand();
  }

  public function advance(): void
  {
    $this->currentFrame()?->advance();
    $this->discardCompletedFrames();
  }

  /** @param array<int, array<string, mixed>> $commands The nested arm. */
  public function pushFrame(array $commands, string $label): void
  {
    if ($commands !== []) {
      $this->frames[] = new EventExecutionFrame($commands, $label);
    }
  }

  /**
   * @param array<string, mixed> $command The waiting command.
   * @param array<string, mixed> $state Its plain pending state.
   */
  public function yieldFor(array $command, array $state = []): void
  {
    $this->pendingCommand = $command;
    $this->pendingState = $state;
    $this->status = EventExecutionStatus::YIELDED;
  }

  /**
   * @param array<string, mixed> $command The suspending command.
   * @param array<string, mixed> $state Its plain suspension state.
   */
  public function suspendFor(array $command, array $state = []): void
  {
    $this->pendingCommand = $command;
    $this->pendingState = $state;
    $this->status = EventExecutionStatus::SUSPENDED;
  }

  public function resumePendingCommand(): void
  {
    $this->status = EventExecutionStatus::YIELDED;
  }

  public function completePendingCommand(): void
  {
    $this->pendingCommand = null;
    $this->pendingState = [];
    $this->status = EventExecutionStatus::RUNNING;
    $this->advance();
  }

  public function updatePendingState(array $state): void
  {
    $this->pendingState = $state;
  }

  public function complete(): void
  {
    $this->pendingCommand = null;
    $this->pendingState = [];
    $this->status = EventExecutionStatus::COMPLETED;
  }

  public function fail(string $message): void
  {
    $this->failureMessage = $message;
    $this->pendingCommand = null;
    $this->pendingState = [];
    $this->status = EventExecutionStatus::FAILED;
  }

  public function hasFinishedFrames(): bool
  {
    $this->discardCompletedFrames();

    return $this->frames === [];
  }

  protected function discardCompletedFrames(): void
  {
    while ($this->frames !== []) {
      $frame = $this->frames[array_key_last($this->frames)];

      if (! $frame->isComplete()) {
        return;
      }

      array_pop($this->frames);
    }
  }
}
