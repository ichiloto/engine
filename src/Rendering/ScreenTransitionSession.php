<?php

namespace Ichiloto\Engine\Rendering;

/** Non-blocking traversal of an existing ScreenTransition frame sequence. */
final class ScreenTransitionSession
{
  protected float $elapsed = 0.0;
  protected int $frameIndex = 0;
  protected ?array $currentFrame = null;
  protected(set) bool $isComplete = false;

  /** @param array<int, array{0: string, 1: int}> $frames */
  public function __construct(
    protected ScreenTransition $transition,
    protected array $frames,
  )
  {
    if (! $transition->isEnabled() || $frames === []) {
      $this->isComplete = true;
    }
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->isComplete) {
      return true;
    }

    $this->elapsed += max(0.0, $deltaSeconds);
    $frameSeconds = max(0.001, ($this->transition->durationMs / 1000) / count($this->frames));

    while ($this->frameIndex < count($this->frames)
      && $this->elapsed + PHP_FLOAT_EPSILON >= $frameSeconds
    ) {
      $this->elapsed -= $frameSeconds;
      [$fill, $columns] = $this->frames[$this->frameIndex++];
      $this->currentFrame = [$fill, $columns];
      $this->transition->renderFrame($fill, $columns);
    }

    $this->isComplete = $this->frameIndex >= count($this->frames);
    return $this->isComplete;
  }

  public function cancel(): void
  {
    $this->isComplete = true;
  }

  public function hasRenderedFrame(): bool
  {
    return $this->currentFrame !== null;
  }

  /** Repaints the most recent frame after a field composition redraw. */
  public function renderCurrentFrame(): void
  {
    if ($this->currentFrame === null) {
      return;
    }

    [$fill, $columns] = $this->currentFrame;
    $this->transition->renderFrame($fill, $columns);
  }
}
