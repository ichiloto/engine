<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Text;

use Ichiloto\Engine\IO\Console\TerminalText;

/** Terminal cell layout only; callers retain semantic icons, selection and values. */
final class TerminalMenuRow
{
  /** Terminal has no record separators, so a data field names its label with this attached suffix. */
  public const string LABEL_SUFFIX = ':';

  public static function composeIconLabel(string $icon, string $label, int $iconWidth = 0): string
  {
    $label = TerminalText::stabilize($label);
    if ($icon === '') { return $label; }
    $icon = TerminalText::stabilize($icon);
    return TerminalText::padRight($icon, max($iconWidth, TerminalText::displayWidth($icon))) . ' ' . $label;
  }

  /** One label/value data field in the shared Terminal style: "Label:" and the value in its own column. */
  public static function composeField(string $label, string $value, int $width, string $prefix = '',
    string $valueAlignment = 'left', ?int $labelWidth = null): string
  {
    return self::compose($label . self::LABEL_SUFFIX, $value, $width, $prefix, valueAlignment: $valueAlignment,
      labelWidth: $labelWidth);
  }

  /** Reserve the value first so fitting a long label never clips a price or quantity. */
  public static function compose(string $label, string $value, int $width, string $prefix = '',
    string $separator = ' ', int $valueWidth = 0, string $valueAlignment = 'right', ?int $labelWidth = null): string
  {
    if ($width <= 0) { return ''; }
    $prefix = TerminalText::stabilize($prefix);
    $separator = TerminalText::stabilize($separator);
    $value = TerminalText::stabilize($value);
    $available = max(0, $width - TerminalText::displayWidth($prefix) - TerminalText::displayWidth($separator));
    $valueWidth = min($available, max($valueWidth, TerminalText::displayWidth($value)));
    $labelWidth = min($available, max(0, $labelWidth ?? ($available - $valueWidth)));
    $valueWidth = $available - $labelWidth;
    $label = TerminalText::padRight($label, $labelWidth);
    $value = TerminalText::fit($value, $valueWidth, $valueAlignment);

    return TerminalText::padRight($prefix . $label . $separator . $value, $width);
  }
}
