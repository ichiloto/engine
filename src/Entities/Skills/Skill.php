<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Battle\CounterAttackRule;
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
  protected(set) ?int $animationId = null;

  /**
   * @var SkillEffect[] The effects of the skill.
   */
  protected(set) array $effects = [];
  /**
   * @var Weapon[] The required weapons of the skill.
   */
  protected(set) array $requiredWeapons = [];

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
   * @param int|null $animationId The stable animation id, or null for legacy selection.
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
    ?int $animationId = null,
    protected(set) ?CounterAttackRule $counterAttack = null,
  )
  {
    $this->animationId = $animationId;
    foreach ($effects as $effect) {
      if ($effect instanceof SkillEffect) {
        $this->effects[] = $effect;
      }
    }

    foreach ($requiredWeapons as $requiredWeapon) {
      if ($requiredWeapon instanceof Weapon) {
        $this->requiredWeapons[] = $requiredWeapon;
      }
    }
  }

  /**
   * Restores declared skill fields, including the historical weapon-list name.
   * @param array<string, mixed> $data
   */
  public function __unserialize(array $data): void
  {
    $this->counterAttack = null;
    foreach ($data as $key => $value) {
      $name = str_contains($key, "\0") ? substr($key, strrpos($key, "\0") + 1) : $key;
      if ($name === 'requiredWeapon') {
        $data['requiredWeapons'] ??= $value;
        unset($data[$key]);
      }
    }
    $type = new \ReflectionObject($this);
    foreach ($data as $key => $value) {
      // PHP's default object serialization qualifies non-public property names.
      $name = str_contains($key, "\0") ? substr($key, strrpos($key, "\0") + 1) : $key;
      if (str_starts_with($key, "\0") && !str_starts_with($key, "\0*\0")) {
        $owner = substr($key, 1, strrpos($key, "\0") - 1);
        if (!is_a($this, $owner)) { throw new \InvalidArgumentException('Invalid serialized skill field owner.'); }
        $property = new \ReflectionProperty($owner, $name);
      } else {
        if (!$type->hasProperty($name)) {
          throw new \InvalidArgumentException('Unknown serialized skill field: ' . $name);
        }
        $property = $type->getProperty($name);
      }
      if ($property->isStatic() || $property->isVirtual()) {
        throw new \InvalidArgumentException('Invalid serialized skill field: ' . $name);
      }
      // Bind to the declaring class so private extension fields retain their
      // identity and typed assignments remain strict, unlike reflection coercion.
      $assign = \Closure::bind(static function (Skill $skill, string $name, mixed $value): void {
        $skill->{$name} = $value;
      }, null, $property->getDeclaringClass()->getName());
      if ($assign === null) { throw new \InvalidArgumentException('Cannot restore serialized skill field.'); }
      $assign($this, $name, $value);
    }
  }
}
