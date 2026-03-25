<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPRecoverySkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\SkillContextInterface;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ItemScope as SkillScope;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;

/**
 * Represents a magic skill with a battle animation hint.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
class MagicSkill extends Skill
{
  /**
   * @param string $name The name of the skill.
   * @param string $description The skill description.
   * @param string $icon The skill icon.
   * @param int $cost The MP cost.
   * @param int $cooldown The skill cooldown.
   * @param SkillScope $scope The skill target scope.
   * @param Occasion $occasion The usage occasion.
   * @param SkillInvocation $invocation The invocation metadata.
   * @param SkillEffect[] $effects The configured skill effects.
   * @param Weapon[] $requiredWeapons The required weapons.
   * @param MagicEffectType|null $effectType The explicit magic animation type.
   */
  public function __construct(
    string $name,
    string $description,
    string $icon,
    int $cost,
    int $cooldown,
    SkillScope $scope = new SkillScope(),
    Occasion $occasion = Occasion::ALWAYS,
    SkillInvocation $invocation = new SkillInvocation(),
    array $effects = [],
    array $requiredWeapons = [],
    protected(set) ?MagicEffectType $effectType = null,
  )
  {
    parent::__construct(
      $name,
      $description,
      $icon,
      $cost,
      $cooldown,
      $scope,
      $occasion,
      $invocation,
      $effects,
      $requiredWeapons,
    );

    $this->effectType ??= $this->inferEffectType($effects);
  }

  /**
   * @inheritDoc
   */
  public function execute(?SkillContextInterface $context): void
  {
    // TODO: Implement execute() method.
  }

  /**
   * Infers the visible magic animation type from the configured effects.
   *
   * @param SkillEffect[] $effects The skill effects to inspect.
   * @return MagicEffectType
   */
  protected function inferEffectType(array $effects): MagicEffectType
  {
    foreach ($effects as $effect) {
      if ($effect instanceof HPRecoverSkillEffect || $effect instanceof MPRecoverySkillEffect) {
        return MagicEffectType::RESTORATIVE;
      }
    }

    foreach ($effects as $effect) {
      if (
        $effect instanceof HPDamageSkillEffect ||
        $effect instanceof MPDamageSkillEffect ||
        $effect instanceof HPDrainSkillEffect ||
        $effect instanceof MPDrainSkillEffect
      ) {
        return MagicEffectType::DESTRUCTIVE;
      }
    }

    return MagicEffectType::BUFF;
  }
}
