<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * What a branch of a mastery region gathers.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
enum MasteryBranchKind: string
{
  /** One of the project's elements; the branch names it. */
  case ELEMENT = 'element';
  /** An aspect of the region's practice (a game might name vitality, status, mana). */
  case ASPECT = 'aspect';
  /** The region's non-elemental practice. */
  case NON_ELEMENTAL = 'non-elemental';
}
