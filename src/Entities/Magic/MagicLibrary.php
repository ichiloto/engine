<?php

namespace Ichiloto\Engine\Entities\Magic;

use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

/**
 * Exposes the project's spells, the magic skills of its skill catalogue, by
 * name.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
final class MagicLibrary
{
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
    return SkillCatalog::getProjectCatalog()->getSpells();
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
}
