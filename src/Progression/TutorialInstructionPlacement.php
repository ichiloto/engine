<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

/** Presentation placement for a tutorial instruction, independent of its target and completion. */
enum TutorialInstructionPlacement: string
{
  case CENTERED = 'centered';
  case CONTEXTUAL = 'contextual';
}
