<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ControlHint;

/**
 * The one terminal owner of how a control reads: a keycap is the control's name in brackets ("[Enter]"),
 * and an action without a control reads "Unbound". Every terminal hint, help line and Controls list
 * formats its controls here, as ControlGlyphPresentation draws them graphically.
 */
final class TerminalControlText
{
  private const string UNBOUND = 'Unbound';
  /** Between one hint and the next on a help line. */
  public const string SEPARATOR = '  ';

  public static function formatControl(?ControlHint $control): string
  {
    return $control === null ? self::UNBOUND : '[' . $control->label . ']';
  }

  /**
   * Every control bound to an action, as the Controls list shows them.
   *
   * @param list<ControlHint> $controls
   */
  public static function formatControls(array $controls): string
  {
    return $controls === [] ? self::UNBOUND : implode(' ', array_map(self::formatControl(...), $controls));
  }

  /** A control (or a previous/next pair of them) and its action's label: "[Enter] Read", "[Q]/[E] Suits". */
  public static function formatHint(ActionHint $hint): string
  {
    $controls = self::formatControl($hint->control)
      . ($hint->pairedAction === null ? '' : '/' . self::formatControl($hint->pairedControl));
    return $controls . ' ' . $hint->label;
  }

  /** @param list<ActionHint> $hints */
  public static function formatHints(array $hints, string $separator = self::SEPARATOR): string
  {
    return implode($separator, array_map(self::formatHint(...), $hints));
  }

  /**
   * A help line from semantic actions and their labels, resolved through the active display profile.
   *
   * @param list<array{string, string}> $actions Action identities and labels, such as [['confirm', 'Select'], ['cancel', 'Back']].
   */
  public static function formatActions(array $actions, string $separator = self::SEPARATOR): string
  {
    return self::formatHints(array_map(static fn(array $action): ActionHint => ActionHints::resolve(...$action), $actions), $separator);
  }
}
