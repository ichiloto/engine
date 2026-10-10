<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Owned inspection state; no constructor-free window or ambient game is needed. */
final readonly class BattlePresentationSnapshot implements BattlePresentationState
{
  public function __construct(private ?BattleCommandPlayback $playback = null,
    private array $feedback = [], private array $selected = [], private array $focused = [],
    private array $queued = [], private float $poseElapsedSeconds = 0) {}

  public function getCommandPlayback(): ?BattleCommandPlayback { return $this->playback; }
  public function getFeedback(): array { return $this->feedback; }
  public function getSelectedBattlers(): array { return $this->selected; }
  public function getFocusedBattlers(): array { return $this->focused; }
  public function getQueuedBattlers(): array { return $this->queued; }
  public function getPoseElapsedSeconds(): float { return $this->poseElapsedSeconds; }
}
