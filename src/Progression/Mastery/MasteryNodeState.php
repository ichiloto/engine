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
  /** EXP the character earns goes into it. */
  case STUDYING = 'studying';
  /** It can be chosen to study. */
  case OPEN = 'open';
  /** Its level or a node it requires is still ahead. */
  case LOCKED = 'locked';
}
