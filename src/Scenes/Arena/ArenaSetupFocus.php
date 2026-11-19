<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

/** Editing context, independent of which visible pane currently owns input. */
enum ArenaSetupFocus: string
{
  case TROOPS = 'troops';
  case PARTY = 'party';
  case MEMBER = 'member';
  case CHOOSER = 'chooser';
  case COMPOSER = 'composer';
  case MANAGEMENT = 'management';
  case ITEMS = 'items';
}
