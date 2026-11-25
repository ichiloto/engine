<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;

/** Resolves battle command hints from the same actions that drive selection. */
final class BattleControlHints
{
  public static function getSelectHint(): ActionHint
  {
    return ActionHints::resolve('action', 'Select');
  }

  public static function getInfoHint(): ActionHint
  {
    return ActionHints::resolve('info', 'Info');
  }

  public static function getBackHint(string $label = 'Back'): ActionHint
  {
    return ActionHints::resolve('battle_back', $label);
  }
}
