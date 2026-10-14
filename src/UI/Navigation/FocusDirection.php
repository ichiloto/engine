<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Navigation;

/** Cardinal navigation intent, independent of control devices and presentation. */
enum FocusDirection: string
{
  case UP = 'up';
  case RIGHT = 'right';
  case DOWN = 'down';
  case LEFT = 'left';
}
