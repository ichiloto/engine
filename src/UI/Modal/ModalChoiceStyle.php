<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\UI\SelectionStyle;

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

  /** The list cursor beside the focused answer, as navigation lists draw it. */
  private const string LIST_CURSOR = '>';

  /**
   * The choices as Terminal lines inside a window of the given inner width: centred buttons, or left-aligned
   * answer rows with the cursor. Every Terminal surface that shows a modal's choices draws them through here.
   *
   * @param list<string> $labels
   * @return list<string>
   */
  public function renderTerminalLines(array $labels, int $activeIndex, int $width): array
  {
    return match ($this) {
      self::BUTTONS => ModalButtonText::renderStacked($labels, $activeIndex, $width),
      self::LIST => array_map(static function (string $label, int $index) use ($activeIndex): string {
        $focused = $index === $activeIndex;
        $line = ' ' . ($focused ? self::LIST_CURSOR : ' ') . ' ' . $label;
        return $focused ? SelectionStyle::apply($line) : $line;
      }, $labels, array_keys($labels)),
    };
  }
}
