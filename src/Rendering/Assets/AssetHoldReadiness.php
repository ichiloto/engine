<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

enum AssetHoldReadiness
{
  case WAITING;
  case READY;
  case REFUSED;
  case UNSUPPORTED;
}
