<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

enum TutorialReplayMode: string
{
  case PRACTICE = 'practice';
  case DEMO = 'demo';
  case NONE = 'none';
}
