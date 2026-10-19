<?php

namespace Ichiloto\Engine\Events\Triggers;

/** Story guidance persists at the screen edge; route guidance belongs to its cell. */
enum EventCueKind: string
{
  case STORY = 'story';
  case ROUTE = 'route';
}
