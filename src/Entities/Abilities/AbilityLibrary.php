<?php

namespace Ichiloto\Engine\Entities\Abilities;

use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Throwable;

/**
 * Loads the project's registered special abilities and exposes them by name.
 *
 * @package Ichiloto\Engine\Entities\Abilities
 */
final class AbilityLibrary
{
  /**
   * @var array<string, SpecialSkill>|null Cached abilities keyed by ability name.
   */
  protected static ?array $cache = null;

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
    if (self::$cache !== null) {
      return self::$cache;
    }

    $loadedAbilities = self::loadSkillsAsset('Data/abilities.php')
      ?? self::loadSkillsAsset('Data/skills.php');

    if (! is_array($loadedAbilities)) {
      self::$cache = [];
      return self::$cache;
    }

    $abilities = [];

    foreach ($loadedAbilities as $key => $skill) {
      if (! $skill instanceof SpecialSkill) {
        continue;
      }

      $abilities[is_string($key) ? $key : $skill->name] = $skill;
    }

    self::$cache = $abilities;

    return self::$cache;
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

  /**
   * Loads an ability asset file if it exists.
   *
   * @param string $path The asset path to load.
   * @return array<mixed>|null The loaded payload, if available.
   */
  protected static function loadSkillsAsset(string $path): ?array
  {
    try {
      $loadedAbilities = asset($path, true);
    } catch (Throwable) {
      return null;
    }

    return is_array($loadedAbilities) ? $loadedAbilities : null;
  }
}
