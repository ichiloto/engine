<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * Why nobody can learn a node yet, whatever their level or points: the
 * project has not finished it or what it opens, or the Engine cannot yet do
 * what it grants.
 * Menus show such a node as unavailable; it never takes points.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
enum MasteryUnavailability: string
{
  /** Its skill's record still awaits author values (see SkillDraft). */
  case AWAITING_VALUES = 'awaiting-values';
  /** It has no price for this character: neither the tree nor the character's map sets one. */
  case UNPRICED = 'unpriced';
  /** It is a foundation that grants no skill yet, and nothing built on it can be learned yet: learning it would gain nothing. */
  case NOTHING_TO_OPEN = 'nothing-to-open';
}
