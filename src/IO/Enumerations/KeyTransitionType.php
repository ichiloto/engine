<?php

namespace Ichiloto\Engine\IO\Enumerations;

/** What a stateful input source observed about a physical control. */
enum KeyTransitionType
{
  /** The control went down; OS repeats of a held control are not presses. */
  case PRESS;
  /** The control came back up. */
  case RELEASE;
  /** The source no longer vouches for any held control (focus loss, for example). */
  case RESET;
}
