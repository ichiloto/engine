<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
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
  public static function getExplicitAnimationId(?BattleAction $action): ?int
  {
    return match (true) {
      $action instanceof SkillBattleAction => $action->skill->animationId,
      $action instanceof ItemBattleAction => $action->item->animationId,
      default => null,
    };
  }

  /** Runtime and previews share ID precedence and exact role selection, never a name fallback. */
  public static function resolveForAction(?BattleAction $action, CharacterInterface $actor,
    AnimationLibrary $library): ?Animation
  {
    $id = self::getExplicitAnimationId($action);
    if ($id !== null) { return $library->findById($id); }
    if ($action instanceof ItemBattleAction) { return null; }
    if ($action instanceof SkillBattleAction) {
      $role = self::getSkillRole($action->skill);
      return $library->findByRole($role === 'attack' ? self::getAttackRole($actor) : $role);
    }
    return $action instanceof AttackAction ? $library->findByRole(self::getAttackRole($actor)) : null;
  }

  /**
   * Inspect actual command consumers without granting abilities or executing them.
   * A missing match means unbound, not necessarily field-only. The owner supplies
   * actions on the animation route; a resolved summon has its own choreography.
   * @param iterable<BattleAction> $actions Actual candidate actions for the animation route.
   * @return list<array{lane: string, animation: Animation, action: BattleAction}>
   */
  public static function findEffectBindings(string $effectId, AnimationLibrary $library,
    CharacterInterface $actor, iterable $actions): array
  {
    \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary::assertId($effectId);
    $bindings = [];
    foreach ($actions as $action) {
      $animation = self::resolveForAction($action, $actor, $library);
      if ($animation === null) { continue; }
      foreach (['source' => $animation->sourceEffect, 'target' => $animation->targetEffect] as $lane => $id) {
        if ($id === $effectId) {
          $bindings[] = ['lane' => $lane, 'animation' => $animation, 'action' => $action];
        }
      }
    }
    return $bindings;
  }

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
    return $actor->attackStyle !== null
      ? 'attack-' . strtolower($actor->attackStyle->value) : 'attack-unarmed';
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
