<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\KeyCode;

/** Shared display resolver. It does not poll, detect devices, cache bindings or handle input. */
final class ActionHints
{
  private static ?ActionHintProvider $provider = null;

  /** The PHP input context may replace its display provider; null restores live keyboard hints. */
  public static function useProvider(?ActionHintProvider $provider): void
  {
    self::$provider = $provider;
  }

  public static function resolve(string $action, string $label, ?KeyCode $keyboardAlias = null): ActionHint
  {
    $provider = self::$provider ?? new InputBindings();
    $control = $provider->controlForAction($action);
    // An owner can declare a real retained keyboard alias, never an invented general fallback.
    if ($control === null && $provider instanceof InputBindings && $keyboardAlias !== null) {
      $control = ControlHint::keyboard($keyboardAlias);
    }
    return new ActionHint($action, $label, $control);
  }
}
