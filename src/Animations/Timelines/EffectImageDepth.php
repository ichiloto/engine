<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

enum EffectImageDepth: string
{
  case FRONT = 'front';
  case BEHIND = 'behind';
  case SUBJECT_FRONT = 'subject-front';

  public static function supportsSubjectOrder(string $anchor): bool
  {
    return $anchor === 'caster' || $anchor === 'target';
  }
}
