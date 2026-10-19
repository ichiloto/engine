<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\States;

/** Authored status meaning, independent of its name, formula or artwork. */
enum StateDisposition: string
{
  case HARMFUL = 'harmful';
  case BENEFICIAL = 'beneficial';
  case NEUTRAL = 'neutral';
}
