<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/**
 * Who draws a battler's contact shadow: the Engine, under its feet on the
 * arena's ground; its artwork, which paints its own (older art), so the
 * Engine never draws a second; or nobody (a creature in water).
 */
enum BattlerShadowSource: string
{
  case ENGINE = 'engine';
  case ARTWORK = 'artwork';
  case NONE = 'none';
}
