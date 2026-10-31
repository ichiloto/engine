<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations;

/** The action binding owns when its caster effect starts on the command clock. */
enum SourceEffectAnchor: string
{
  case SOURCE = 'source';
  case TARGET = 'target';
}
