<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

enum AssetHoldState: string
{
  case PENDING = 'pending';
  case HELD = 'held';
  case REFUSED = 'refused';
  case ENDED = 'ended';
}
