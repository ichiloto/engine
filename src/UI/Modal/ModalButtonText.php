<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\SelectionStyle;
use InvalidArgumentException;

/**
 * The terminal presentation of a modal's choices, shared by every modal. A modal's choices are buttons: each label is
 * centred in its space, and the focused one carries the steady selection colour, with no list cursor. Navigation
 * lists (menus) keep their own left-aligned rows and cursor. The graphical counterpart is MenuModalPresentation,
 * which draws the same choices as theme buttons.
 */
final class ModalButtonText
{
  /** What separates buttons laid out on one line. */
  private const string INLINE_GAP = ' ';

  private function __construct()
  {
  }

  /**
   * The buttons on one line, in order; the caller centres the line.
   *
   * @param list<string> $labels
   */
  public static function renderInline(array $labels, int $activeIndex): string
  {
    return implode(self::INLINE_GAP, self::styleLabels($labels, $activeIndex));
  }

  /**
   * One line per button, each label centred in the given width.
   *
   * @param list<string> $labels
   * @return list<string>
   */
  public static function renderStacked(array $labels, int $activeIndex, int $width): array
  {
    $lines = [];
    foreach (self::styleLabels($labels, $activeIndex) as $index => $styled) {
      // Centred by the label's own width, so the focus colour covers the label and not its padding.
      $padding = max(0, $width - TerminalText::displayWidth($labels[$index]));
      $left = intdiv($padding, 2);
      $lines[] = str_repeat(' ', $left) . $styled . str_repeat(' ', $padding - $left);
    }

    return $lines;
  }

  /**
   * The labels with the focused one in the selection colour, chosen by its index so that a label repeated inside
   * another ("No" in "Not now") never lights up the wrong button.
   *
   * @param list<string> $labels
   * @return list<string>
   */
  private static function styleLabels(array $labels, int $activeIndex): array
  {
    if (!array_is_list($labels)) {
      throw new InvalidArgumentException('Modal buttons are an ordered list of labels.');
    }

    return array_map(static fn(string $label, int $index): string =>
      $index === $activeIndex && $label !== '' ? SelectionStyle::apply($label) : $label, $labels, array_keys($labels));
  }
}
