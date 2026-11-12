<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Independently timed parts of one pose's authored sheet. */
enum BattlePoseSequence: string
{
  case MAIN = 'main';
  case ENTRY = 'entry';
  case EXIT = 'exit';
  case GUARD = 'guard';
}
