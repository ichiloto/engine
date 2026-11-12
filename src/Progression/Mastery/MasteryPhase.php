<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * The phase of a special-grade node: the demi phase at half effectiveness,
 * then the full phase. The skills own the effects; the phase places the node.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
enum MasteryPhase: string
{
  case DEMI = 'demi';
  case FULL = 'full';
}
