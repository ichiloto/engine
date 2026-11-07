<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

/** The gameplay owner that can start an encountered tutorial. */
enum TutorialStartContext: string
{
  case FIELD = 'field';
  case SUMMONS_LIBRARY = 'summons-library';
  case BATTLE_COMMAND = 'battle-command';
}
