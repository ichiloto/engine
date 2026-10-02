<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;

/** Shared semantic selection; legacy names remain only for Editor migration diagnostics. */
final class ActionAnimationResolver
{
  public static function getSkillRole(Skill $skill): string
  {
    if ($skill instanceof BasicSkill) {
      return 'attack';
    }

    return match ($skill instanceof MagicSkill ? $skill->effectType : null) {
      MagicEffectType::RESTORATIVE, MagicEffectType::BUFF => 'restorative',
      default => 'skill',
    };
  }

  /**
   * @deprecated Editor compatibility diagnostics only; runtime no longer infers bindings by names.
   * @return string[] Legacy names for migration, not runtime selection.
   */
  public static function getSkillCandidateNames(string $skillName, ?MagicEffectType $magicEffectType): array
  {
    $fallback = match ($magicEffectType) {
      MagicEffectType::RESTORATIVE, MagicEffectType::BUFF => 'Healing Aura',
      default => 'Hit Spark',
    };

    return [$skillName, $fallback];
  }
}
