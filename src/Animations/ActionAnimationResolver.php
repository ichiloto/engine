<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

/** Shared semantic selection; legacy names remain only for Editor migration diagnostics. */
final class ActionAnimationResolver
{
  /** Project bindings supply the choreography; weapon names and damage do not. */
  public static function getAttackRole(CharacterInterface $actor): string
  {
    if (!$actor instanceof Character) { return 'attack'; }
    foreach ($actor->equipment as $slot) {
      if ($slot->equipment instanceof Weapon) {
        return $slot->equipment->equipmentType instanceof WeaponType
          ? 'attack-' . strtolower($slot->equipment->equipmentType->value) : 'attack';
      }
    }
    return 'attack-unarmed';
  }

  /** @return list<string> */
  public static function getSupportedRoles(): array
  {
    return ['attack', 'skill', 'restorative', 'attack-unarmed',
      ...array_map(static fn(WeaponType $type): string => 'attack-' . strtolower($type->value), WeaponType::cases())];
  }

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
