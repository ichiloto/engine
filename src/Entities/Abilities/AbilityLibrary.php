<?php

namespace Ichiloto\Engine\Entities\Abilities;

use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/**
 * Exposes the project's special abilities, the special skills of its skill
 * catalogue, by name.
 *
 * @package Ichiloto\Engine\Entities\Abilities
 */
final class AbilityLibrary
{
  /**
   * AbilityLibrary constructor.
   */
  private function __construct()
  {
  }

  /**
   * Returns all known special abilities keyed by name.
   *
   * @return array<string, SpecialSkill> The registered abilities.
   */
  public static function all(): array
  {
    return SkillCatalog::getProjectCatalog()->getAbilities();
  }

  /**
   * Finds a registered ability by name.
   *
   * @param string $name The ability name.
   * @return SpecialSkill|null The matching ability, if found.
   */
  public static function find(string $name): ?SpecialSkill
  {
    return self::all()[$name] ?? null;
  }
}
