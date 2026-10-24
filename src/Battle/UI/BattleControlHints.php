<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Enumerations\KeyCode;

/**
 * The battle command flow's controls and the hints that show them. Info and Back read fixed keys,
 * so their hints name those keys; choosing reads the semantic action.
 */
final class BattleControlHints
{
  /** Shows the focused option's information. */
  public const array INFO_KEYS = [KeyCode::I, KeyCode::i];
  /** Returns to the previous selection, or the previous character. */
  public const array BACK_KEYS = [KeyCode::C, KeyCode::c];

  public static function getSelectHint(): ActionHint
  {
    return ActionHints::resolve('action', 'Select');
  }

  public static function getInfoHint(): ActionHint
  {
    return new ActionHint('battle_info', 'Info', ControlHint::keyboard(self::INFO_KEYS[0]));
  }

  public static function getBackHint(string $label = 'Back'): ActionHint
  {
    return new ActionHint('battle_back', $label, ControlHint::keyboard(self::BACK_KEYS[0]));
  }
}
