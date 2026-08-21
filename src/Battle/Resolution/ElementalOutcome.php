<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Explicit elemental outcome; presentation never infers this from HP deltas. */
enum ElementalOutcome: string
{
  case NORMAL = 'normal';
  case WEAK = 'weak';
  case RESIST = 'resist';
  case NULL = 'null';
  case ABSORB = 'absorb';
}
