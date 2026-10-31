<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Saves\SaveFeatures;
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
    return new ActionHint($action, $label, self::resolveControl($action, $keyboardAlias));
  }

  /**
   * The control the active display profile shows for an action, for a hint that shows the control alone
   * (a dialogue's advance key). Every hint resolves its control here, so a rebound key or another
   * profile's control family shows the same way everywhere.
   */
  public static function resolveControl(string $action, ?KeyCode $keyboardAlias = null): ?ControlHint
  {
    if ($action === 'quick_save' && !SaveFeatures::isQuickSaveEnabled()) {
      return null;
    }
    $provider = self::$provider ?? new InputBindings();
    $control = $provider->controlForAction($action);
    // An owner can declare a real retained keyboard alias, never an invented general fallback.
    if ($control === null && $provider instanceof InputBindings && $keyboardAlias !== null) {
      $control = ControlHint::keyboard($keyboardAlias);
    }
    return $control;
  }

  /**
   * One hint for a previous/next pair of actions that share a label ("Q / E Suits").
   * A side whose action has no control reads as unbound.
   */
  public static function resolvePair(string $previous, string $next, string $label): ActionHint
  {
    return new ActionHint($previous, $label, self::resolve($previous, $label)->control, self::resolve($next, $label)->control, $next);
  }
}
