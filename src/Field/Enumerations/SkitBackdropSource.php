<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Enumerations;

/**
 * Which authored record gave a skit its backdrop, in the order they are asked: the skit itself, the map it plays
 * on, that map's region, or none of them.
 *
 * @package Ichiloto\Engine\Field\Enumerations
 */
enum SkitBackdropSource: string
{
  /** The skit's own `backdrop`: its content needs that setting wherever it plays. */
  case SKIT = 'skit';
  /** The current map's `skitBackdrop`. */
  case MAP = 'map';
  /** The `skitBackdrop` of the current map's region, in `Data/regions.php`. */
  case REGION = 'region';
  /** Nothing names one. */
  case NONE = 'none';
}
