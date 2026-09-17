<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

enum PauseAction: string
{
  case RESUME = 'Resume';
  case CONFIG = 'Config';
  case TITLE = 'To Title';
  case EXIT = 'Exit';
}
