<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

/** The Engine's initial preparation decision, separate from renderer residency. */
enum AssetHoldPreparationState: string
{
  case WAITING = 'waiting';
  case READY = 'ready';
  case REFUSED = 'refused';
  case UNSUPPORTED = 'unsupported';
  case TIMED_OUT = 'timed_out';
  case SUPERSEDED = 'superseded';
  case CANCELLED = 'cancelled';
}
