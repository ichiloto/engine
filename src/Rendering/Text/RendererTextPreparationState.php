<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Text;

enum RendererTextPreparationState
{
  case PENDING;
  case PREPARED;
  case FAILED;
  case RELEASED;
  case RETIRED;
}
