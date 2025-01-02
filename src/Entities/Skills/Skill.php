<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\SkillInterface;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ItemScope as SkillScope;

/**
 * Represents a skill.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
abstract class Skill implements SkillInterface
{
  /**
   * @var SkillEffect[] The effects of the skill.
   */
  protected(set) array $effects = [];
  /**
   * @var Weapon[] The required weapons of the skill.
   */
  protected(set) array $requiredWeapon = [];

  /**
   * Skill constructor.
   *
   * @param string $name The name of the skill.
   * @param string $description The description of the skill.
   * @param string $icon The icon of the skill.
   * @param int $cost The cost of the skill.
   * @param int $cooldown The cooldown of the skill.
   * @param SkillScope $scope The scope of the skill.
   * @param Occasion $occasion The occasion of the skill.
   * @param SkillInvocation $invocation The invocation of the skill.
   * @param SkillEffect[] $effects The effects of the skill.
   * @param Weapon[] $requiredWeapons The required weapons of the skill.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $description,
    protected(set) string $icon,
    protected(set) int $cost,
    protected(set) int $cooldown,
    protected(set) SkillScope $scope = new SkillScope(),
    protected(set) Occasion $occasion = Occasion::ALWAYS,
    protected(set) SkillInvocation $invocation = new SkillInvocation(),
    array $effects = [],
    array $requiredWeapons = [],
  )
  {
    foreach ($effects as $effect) {
      if ($effect instanceof SkillEffect) {
        $this->effects[] = $effect;
      }
    }

    foreach ($requiredWeapons as $requiredWeapon) {
      if ($requiredWeapon instanceof Weapon) {
        $this->requiredWeapon[] = $requiredWeapon;
      }
    }
  }
}