<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Rendering reads presentation state without owning a scene or its gameplay. */
interface BattlePresentationState
{
  public function getCommandPlayback(): ?BattleCommandPlayback;
  public function getFeedback(): array;
  public function getSelectedBattlers(): array;
  public function getFocusedBattlers(): array;
  public function getQueuedBattlers(): array;
  public function getPoseElapsedSeconds(): float;
}
