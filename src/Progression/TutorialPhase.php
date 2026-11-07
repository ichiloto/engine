<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

enum TutorialPhase: string
{
  case PENDING = 'pending';
  case COMPLETED = 'completed';
  case SKIPPED = 'skipped';
}
