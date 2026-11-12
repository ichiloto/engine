<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

enum SkillSecondaryTrigger: string
{
  case ALWAYS = 'always';
  case LANDED_HIT = 'landedHit';

  public function permits(SkillEffectContext $context, CharacterInterface $target): bool
  {
    return $this === self::ALWAYS || $context->hasLandedDamageOn($target);
  }
}
