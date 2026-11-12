<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * Where the Mastery menu's focus is, from the top down: on a discipline's
 * tab, on one of its families, or on a node of a family's tree. Confirm goes
 * one level deeper and Cancel one level back, as the other menus' modes do;
 * both renderers show the focus at the level it is on.
 */
enum MasteryMenuLevel: string
{
  /** Choosing a discipline (a card suit, a school). */
  case DISCIPLINE = 'discipline';
  /** Choosing one of the shown discipline's families. */
  case FAMILY = 'family';
  /** Moving along a family's tree and learning its nodes. */
  case TREE = 'tree';
}
