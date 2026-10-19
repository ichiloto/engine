<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Throwable;

/** Read-only effect lane shared by commands and battler-attached conditions. */
interface BattleEffectPlayback
{
  public CharacterInterface $actor { get; }
  public array $targets { get; }
  public function getActiveSegments(bool $reducedMotion = false, bool $terminal = false): array;
  public function recordPresentationFailure(Throwable $failure): void;
}
