<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\UI\Modal\ModalChoiceStyle;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\SelectionStyle;

/**
 * The answers to a line of dialogue. Andrew, 21 November 2026: "Dialogue stays a list". The answers are a
 * navigation list: left-aligned rows with the cursor, unlike a modal's own decision, whose choices are buttons.
 * It keeps the select modal's input, lifecycle and outcome.
 */
class DialogueChoiceModal extends SelectModal
{
  /** The list cursor beside the focused answer, as navigation lists draw it. */
  private const string CURSOR = '>';

  protected function getChoiceStyle(): ModalChoiceStyle
  {
    return ModalChoiceStyle::LIST;
  }

  /** @return list<string> One left-aligned row per answer, the focused one with the cursor and selection colour. */
  protected function renderChoiceLines(): array
  {
    $lines = [];
    foreach (array_values($this->options) as $index => $option) {
      $focused = $index === $this->activeOptionIndex;
      $line = ' ' . ($focused ? self::CURSOR : ' ') . ' ' . $option;
      $lines[] = $focused ? SelectionStyle::apply($line) : $line;
    }

    return $lines;
  }
}
