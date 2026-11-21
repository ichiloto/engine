<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

/**
 * What a modal's choices are, which decides how every renderer draws them. Andrew, 21 November 2026: "Modal have
 * buttons which should be center aligned" and "Dialogue stays a list".
 */
enum ModalChoiceStyle
{
  /** The modal's own decision (Quit, confirmations, pause menus): centred buttons with a steady focus. */
  case BUTTONS;
  /** Answers in a conversation: a navigation list of left-aligned rows with the cursor. */
  case LIST;
}
