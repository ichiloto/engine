<?php

namespace Ichiloto\Engine\Events\Interpreter;

/** One deterministic cooperative lane inside an event execution session. */
final class EventExecutionLane
{
  /** @var EventExecutionFrame[] */
  protected array $frames = [];

  protected(set) ?array $pendingCommand = null;
  protected(set) array $pendingState = [];
  protected(set) EventExecutionStatus $status = EventExecutionStatus::RUNNING;
  protected(set) ?EventPendingOperationInterface $pendingOperation = null;
  protected(set) ?EventParallelGroup $parallelGroup = null;
  protected(set) ?array $frameToPush = null;
  protected(set) ?string $failureMessage = null;

  /** @param array<int, array<string, mixed>> $commands */
  public function __construct(array $commands, public readonly string $path, string $label)
  {
    $this->frames[] = new EventExecutionFrame($commands, $label);
  }

  /** @return EventExecutionFrame[] */
  public function frames(): array
  {
    return $this->frames;
  }

  public function currentFrame(): ?EventExecutionFrame
  {
    $this->discardCompletedFrames();
    return $this->frames[array_key_last($this->frames)] ?? null;
  }

  public function currentCommand(): ?array
  {
    return $this->currentFrame()?->currentCommand();
  }

  public function commandPath(): string
  {
    $parts = [];

    foreach ($this->frames as $frame) {
      $parts[] = sprintf('%s[%d]', $frame->label, $frame->commandIndex + 1);
    }

    return $this->path . '/' . implode('/', $parts);
  }

  public function advance(): void
  {
    $this->currentFrame()?->advance();
    $this->discardCompletedFrames();
  }

  /** @param array<int, array<string, mixed>> $commands */
  public function pushFrame(array $commands, string $label): void
  {
    if ($commands !== []) {
      $this->frames[] = new EventExecutionFrame($commands, $label);
    }
  }

  /** @param array<int, array<string, mixed>> $commands */
  public function queueFrame(array $commands, string $label): void
  {
    $this->frameToPush = ['commands' => $commands, 'label' => $label];
  }

  public function pushQueuedFrame(): void
  {
    if ($this->frameToPush === null) {
      return;
    }

    $this->pushFrame($this->frameToPush['commands'], $this->frameToPush['label']);
    $this->frameToPush = null;
  }

  public function yieldFor(array $command, array $state = [], ?EventPendingOperationInterface $operation = null): void
  {
    $this->pendingCommand = $command;
    $this->pendingState = $state;
    $this->pendingOperation = $operation;
    $this->status = EventExecutionStatus::YIELDED;
  }

  public function yieldForParallel(array $command, EventParallelGroup $group): void
  {
    $this->parallelGroup = $group;
    $this->yieldFor($command, ['kind' => 'parallel']);
    $this->parallelGroup = $group;
  }

  public function suspendFor(array $command, array $state = []): void
  {
    $this->pendingCommand = $command;
    $this->pendingState = $state;
    $this->status = EventExecutionStatus::SUSPENDED;
  }

  public function updatePendingState(array $state): void
  {
    $this->pendingState = $state;
  }

  public function completePendingCommand(): void
  {
    $this->pendingOperation = null;
    $this->parallelGroup = null;
    $this->pendingCommand = null;
    $this->pendingState = [];
    $this->status = EventExecutionStatus::RUNNING;
    $this->advance();
  }

  public function complete(): void
  {
    $this->cancelPending();
    $this->status = EventExecutionStatus::COMPLETED;
  }

  public function fail(string $message): void
  {
    if ($this->status === EventExecutionStatus::FAILED) {
      return;
    }
    $this->failureMessage = $message;
    $this->status = EventExecutionStatus::FAILED;
    $this->cancelPending();
  }

  public function cancel(): void
  {
    try {
      $this->cancelPending();
    } finally {
      $this->frames = [];
      if ($this->status !== EventExecutionStatus::FAILED) {
        $this->status = EventExecutionStatus::COMPLETED;
      }
    }
  }

  public function hasFinishedFrames(): bool
  {
    $this->discardCompletedFrames();
    return $this->frames === [];
  }

  protected function cancelPending(): void
  {
    $operation = $this->pendingOperation;
    $group = $this->parallelGroup;
    // Detach first so throwing or reentrant cancellation cannot run an operation twice.
    $this->pendingOperation = null;
    $this->parallelGroup = null;
    $this->pendingCommand = null;
    $this->pendingState = [];
    $this->frameToPush = null;
    $failures = [];
    foreach ([$operation, $group] as $pending) {
      try {
        $pending?->cancel();
      } catch (\Throwable $failure) {
        $failures[] = $failure;
      }
    }
    if ($failures !== []) {
      throw new \RuntimeException(sprintf('Lane "%s" cancellation failed: %s', $this->path,
        implode('; ', array_map(static fn(\Throwable $failure): string => $failure->getMessage(), $failures))),
        previous: $failures[0]);
    }
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
