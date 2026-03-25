<?php

namespace Ichiloto\Engine\Entities\Magic;

use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Throwable;

/**
 * Loads the project's registered magic skills and exposes them by name.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
final class MagicLibrary
{
  /**
   * @var array<string, MagicSkill>|null Cached magic skills keyed by skill name.
   */
  protected static ?array $cache = null;

  /**
   * MagicLibrary constructor.
   */
  private function __construct()
  {
  }

  /**
   * Returns all known magic skills keyed by spell name.
   *
   * @return array<string, MagicSkill> The registered magic skills.
   */
  public static function all(): array
  {
    if (self::$cache !== null) {
      return self::$cache;
    }

    $loadedSkills = self::loadSkillsAsset('Data/magic.php')
      ?? self::loadSkillsAsset('Data/skills.php');

    if (! is_array($loadedSkills)) {
      self::$cache = [];
      return self::$cache;
    }

    $magicSkills = [];

    foreach ($loadedSkills as $key => $skill) {
      if (! $skill instanceof MagicSkill) {
        continue;
      }

      $magicSkills[is_string($key) ? $key : $skill->name] = $skill;
    }

    self::$cache = $magicSkills;

    return self::$cache;
  }

  /**
   * Finds a registered magic skill by name.
   *
   * @param string $name The spell name.
   * @return MagicSkill|null The matching spell, if found.
   */
  public static function find(string $name): ?MagicSkill
  {
    return self::all()[$name] ?? null;
  }

  /**
   * Loads a skills asset file if it exists.
   *
   * @param string $path The asset path to load.
   * @return array<mixed>|null The loaded payload, if available.
   */
  protected static function loadSkillsAsset(string $path): ?array
  {
    try {
      $loadedSkills = asset($path, true);
    } catch (Throwable) {
      return null;
    }

    return is_array($loadedSkills) ? $loadedSkills : null;
  }
}
