<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * Why a node cannot be learned, so a menu can say it in the player's words.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
enum MasteryRefusalReason
{
  /** The map has no such node, or its skill is missing from the catalogue. */
  case UNKNOWN;
  /** The character already knows it. */
  case LEARNED;
  /** Its level or a node it requires is still ahead. */
  case LOCKED;
  /** The character holds fewer points than its price. */
  case SHORT;
}
