<?php

namespace Ichiloto\Engine\Util\Stores;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Roles\ExperienceCurveGenerator;
use Ichiloto\Engine\Entities\Roles\ParameterCurveGenerator;
use Ichiloto\Engine\Entities\Roles\SkillToLearn;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Loads the project's character classes from `assets/Data/classes.php`.
 *
 * A class definition is pure data:
 *
 * ```php
 * [
 *   'id' => 1,
 *   'name' => 'Vanguard',
 *   'description' => '…',
 *   'note' => '',
 *   'experienceCurve' => ['baseValue' => 30, 'extraValue' => 20, 'accelerationA' => 30, 'accelerationB' => 30],
 *   'parameterCurves' => [
 *     'totalHp' => ['baseValue' => 140, 'extraGrowth' => 520, 'flatIncrement' => 42],
 *     …one entry per stat…
 *   ],
 *   'skillsToLearn' => [
 *     ['level' => 4, 'skill' => 'Dual Slash', 'note' => ''],
 *   ],
 * ]
 * ```
 *
 * A {@see CharacterRole} needs its character (its curves are seeded from
 * that character's level and stats), so the store keeps definitions and
 * builds roles on demand through {@see self::createRole()}.
 *
 * @package Ichiloto\Engine\Util\Stores
 */
class ClassStore
{
  /**
   * The stat names that accept parameter curves.
   */
  protected const array CURVED_STATS = [
    'totalHp',
    'totalMp',
    'attack',
    'defence',
    'magicAttack',
    'magicDefence',
    'speed',
    'grace',
    'evasion',
  ];

  /**
   * @var array<string, array<string, mixed>>|null The cached class definitions, keyed by lowercase name.
   */
  protected static ?array $definitions = null;

  /**
   * Returns every authored class definition, keyed by lowercase name.
   *
   * @return array<string, array<string, mixed>> The class definitions.
   */
  public static function all(): array
  {
    if (self::$definitions !== null) {
      return self::$definitions;
    }

    self::$definitions = [];
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'classes.php');

    if (! file_exists($filename)) {
      return self::$definitions;
    }

    $entries = require $filename;

    foreach (is_array($entries) ? $entries : [] as $entry) {
      if (! is_array($entry)) {
        continue;
      }

      $name = trim(strval($entry['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      self::$definitions[strtolower($name)] = $entry;
    }

    return self::$definitions;
  }

  /**
   * Determines whether a class name is authored.
   *
   * @param string $className The class name.
   * @return bool True when the class exists.
   */
  public static function has(string $className): bool
  {
    return isset(self::all()[strtolower(trim($className))]);
  }

  /**
   * Builds the role for a class, bound to the given character.
   *
   * @param string $className The class name (as authored in classes.php).
   * @param Character $character The character the role belongs to.
   * @return CharacterRole|null The role, or null when the class is unknown.
   */
  public static function createRole(string $className, Character $character): ?CharacterRole
  {
    $definition = self::all()[strtolower(trim($className))] ?? null;

    if ($definition === null) {
      return null;
    }

    $curves = is_array($definition['parameterCurves'] ?? null) ? $definition['parameterCurves'] : [];
    $generators = [];

    foreach (self::CURVED_STATS as $stat) {
      $generators[$stat] = self::makeParameterCurve($character, $stat, $curves[$stat] ?? null);
    }

    return new CharacterRole(
      $character,
      strval($definition['name']),
      self::makeExperienceCurve(is_array($definition['experienceCurve'] ?? null) ? $definition['experienceCurve'] : []),
      self::resolveSkillsToLearn($definition['skillsToLearn'] ?? []),
      is_array($definition['traits'] ?? null) ? $definition['traits'] : [],
      strval($definition['note'] ?? ''),
      $generators['totalHp'],
      $generators['totalMp'],
      $generators['attack'],
      $generators['defence'],
      $generators['magicAttack'],
      $generators['magicDefence'],
      $generators['speed'],
      $generators['grace'],
      $generators['evasion'],
      self::resolveEquipmentTypes($definition['equipment']['weapons'] ?? [], isWeapon: true),
      self::resolveEquipmentTypes($definition['equipment']['armor'] ?? [], isWeapon: false),
    );
  }

  /**
   * Resolves an authored equipment-restriction list into enum cases.
   *
   * @param mixed $names The authored type names.
   * @param bool $isWeapon True for weapon types, false for armor types.
   * @return array<int, WeaponType|ArmorType> The resolved types.
   */
  protected static function resolveEquipmentTypes(mixed $names, bool $isWeapon): array
  {
    if (! is_array($names)) {
      return [];
    }

    $types = [];

    foreach ($names as $name) {
      $type = Equipment::resolveEquipmentType($name, $isWeapon);

      if ($type !== null) {
        $types[] = $type;
      } elseif (is_string($name) && trim($name) !== '') {
        Debug::warn(sprintf('Class references unknown equipment type: %s', $name));
      }
    }

    return $types;
  }

  /**
   * Clears the cache (tests and hot reloads).
   *
   * @return void
   */
  public static function reset(): void
  {
    self::$definitions = null;
  }

  /**
   * Builds one parameter curve, falling back to the character's own stat
   * when the class does not author that curve.
   *
   * @param Character $character The character.
   * @param string $stat The stat name.
   * @param array<string, mixed>|null $curve The authored curve entry.
   * @return ParameterCurveGenerator|null The generator, or null to keep the engine default.
   */
  protected static function makeParameterCurve(
    Character $character,
    string $stat,
    ?array $curve
  ): ?ParameterCurveGenerator
  {
    if ($curve === null) {
      return null;
    }

    $statProperty = match ($stat) {
      'totalHp' => 'totalHp',
      'totalMp' => 'totalMp',
      default => 'total' . ucfirst($stat),
    };

    $baseValue = intval($curve['baseValue'] ?? ($character->stats->$statProperty ?? 1));

    return new ParameterCurveGenerator(
      $character->level,
      $baseValue,
      intval($curve['extraGrowth'] ?? 50),
      intval($curve['flatIncrement'] ?? 1),
    );
  }

  /**
   * Builds the experience curve.
   *
   * @param array<string, mixed> $curve The authored curve entry.
   * @return ExperienceCurveGenerator The generator.
   */
  protected static function makeExperienceCurve(array $curve): ExperienceCurveGenerator
  {
    return new ExperienceCurveGenerator(
      intval($curve['baseValue'] ?? 30),
      intval($curve['extraValue'] ?? 20),
      intval($curve['accelerationA'] ?? 30),
      intval($curve['accelerationB'] ?? 30),
    );
  }

  /**
   * Resolves the class's level-gated skill grants.
   *
   * Entries name a skill from `assets/Data/skills.php`:
   * `['level' => 4, 'skill' => 'Dual Slash', 'note' => '']`.
   *
   * @param mixed $entries The authored `skillsToLearn` entries.
   * @return SkillToLearn[] The resolved grants.
   */
  protected static function resolveSkillsToLearn(mixed $entries): array
  {
    if (! is_array($entries) || empty($entries)) {
      return [];
    }

    $skillsByName = self::loadSkillsByName();
    $grants = [];

    foreach ($entries as $entry) {
      if ($entry instanceof SkillToLearn) {
        $grants[] = $entry;
        continue;
      }

      if (! is_array($entry)) {
        continue;
      }

      $skillName = trim(strval($entry['skill'] ?? ''));
      $skill = $skillsByName[$skillName] ?? null;

      if (! $skill instanceof Skill) {
        Debug::warn(sprintf('Class references unknown skill: %s', $skillName));
        continue;
      }

      $grants[] = new SkillToLearn(
        max(1, intval($entry['level'] ?? 1)),
        $skill,
        strval($entry['note'] ?? ''),
      );
    }

    return $grants;
  }

  /**
   * Loads the project's battle skills, keyed by name.
   *
   * @return array<string, Skill> The skills.
   */
  protected static function loadSkillsByName(): array
  {
    static $skills = null;

    if ($skills !== null) {
      return $skills;
    }

    $skills = [];
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'skills.php');

    if (! file_exists($filename)) {
      return $skills;
    }

    try {
      foreach ((array) require $filename as $skill) {
        if ($skill instanceof Skill) {
          $skills[$skill->name] = $skill;
        }
      }
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Could not load skills for class grants: %s', $exception->getMessage()));
    }

    return $skills;
  }
}
