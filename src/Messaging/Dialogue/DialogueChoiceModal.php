<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\UI\Modal\ModalChoiceStyle;
use Ichiloto\Engine\UI\Modal\SelectModal;

/**
 * The answers to a line of dialogue. Andrew, 21 November 2026: "Dialogue stays a list". The answers are a
 * navigation list: left-aligned rows with the cursor, unlike a modal's own decision, whose choices are buttons.
 * It keeps the select modal's input, lifecycle and outcome.
 */
class DialogueChoiceModal extends SelectModal
{
  protected function getChoiceStyle(): ModalChoiceStyle
  {
    return ModalChoiceStyle::LIST;
  }
}
