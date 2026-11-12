<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

/** Whether a waymark marks the objective itself or the way toward it. */
enum FieldWaymarkRole: string
{
  /** The objective's subject on the current map. */
  case TARGET = 'target';
  /** The exit on the current map that leads toward an objective elsewhere. */
  case ROUTE = 'route';
}
