<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** An exact requested approach could not be planned; no substitute destination is selected. */
enum BattleTravelFailure: string
{
  case OUTSIDE_ARENA = 'outside-arena';
  case START_OCCUPIED = 'start-occupied';
  case CONTACT_OCCUPIED = 'contact-occupied';
  case NO_ROUTE = 'no-route';
  case CAPACITY = 'capacity';
}
