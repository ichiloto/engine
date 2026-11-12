<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * What a node of the mastery tree is to its branch, which also places it in
 * the branch's chain: a branch begins with its foundation, climbs its graded
 * applications, and ends in its advanced applications, each of which an
 * evolution may carry further; a special grade runs from its demi phase to
 * its full phase.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
enum MasteryNodeKind: string
{
  /** Learning the basis of the branch (conjuring an element, learning an aspect). What it grants is authored. */
  case FOUNDATION = 'foundation';
  /** A basic application: one skill, graded (I, II, III) when it climbs the branch's ladder. */
  case APPLICATION = 'application';
  /**
   * An advanced application: one skill at the end of the branch, after its highest grade; or an evolution of another
   * advanced application (a single-target spell's multi-target form), after the application it extends.
   */
  case ADVANCED = 'advanced';
  /** A special grade: one skill, in a demi or full phase. */
  case SPECIAL = 'special';
}
