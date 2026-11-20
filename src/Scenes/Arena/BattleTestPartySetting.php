<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

enum BattleTestPartySetting: string
{
  case MEMBERS = 'members';
  case FRONTLINE = 'frontline';
  case OPENING = 'opening';
  case ITEMS = 'items';
}
