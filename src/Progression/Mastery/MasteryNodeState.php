<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * Where a node stands for one character.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
enum MasteryNodeState: string
{
  /** The character knows its skill. */
  case LEARNED = 'learned';
  /** Its level and the nodes it requires are reached; it can be learned once the character holds its price. */
  case OPEN = 'open';
  /** Its level or a node it requires is still ahead. */
  case LOCKED = 'locked';
  /** Nobody can learn it yet: the project has not finished it, or the Engine cannot yet grant it (see MasteryUnavailability). */
  case UNAVAILABLE = 'unavailable';
}
