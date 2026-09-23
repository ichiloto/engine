<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Entities\Magic\MagicEffectType;

/** Shared legacy name selection for runtime and authoring diagnostics. */
final class ActionAnimationResolver
{
  /** @return string[] Ordered names to try when a skill has no explicit animation id. */
  public static function getSkillCandidateNames(string $skillName, ?MagicEffectType $magicEffectType): array
  {
    $fallback = match ($magicEffectType) {
      MagicEffectType::RESTORATIVE, MagicEffectType::BUFF => 'Healing Aura',
      default => 'Hit Spark',
    };

    return [$skillName, $fallback];
  }
}
