<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

enum TutorialPhase: string
{
  /** Available in Records after a quiet discovery, with no automatic prompt queued. */
  case DISCOVERED = 'discovered';
  case PENDING = 'pending';
  case COMPLETED = 'completed';
  case SKIPPED = 'skipped';

  public function isFinished(): bool
  {
    return $this === self::COMPLETED || $this === self::SKIPPED;
  }
}
