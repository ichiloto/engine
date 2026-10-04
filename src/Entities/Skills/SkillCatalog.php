<?php

namespace Ichiloto\Engine\Entities\Skills;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/**
 * The project's skill catalogue: every skill the project authors, in one
 * identity space.
 *
 * A project authors each skill in a record file of its own under
 * `assets/Data/Skills`, returning `['class' => Skill::class, 'data' => [...]]`,
 * the data in the form {@see SkillRecord} reads; `skills.php` is the barrel
 * that returns them. Which file a skill lives in is authoring organisation
 * only: a skill is identified by its name across all of them, and its kind
 * comes from its record (a spell builds a MagicSkill, an ability a
 * SpecialSkill, an attack a BasicSkill). Every consumer
 * that resolves a skill by name reads this catalogue, so a skill is found the
 * same way wherever it is authored.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
final class SkillCatalog
{
  /** The record folder, relative to `assets/Data`. */
  public const string DIRECTORY = 'Skills';

  /**
   * @var array<string, self> The running project's catalogue, keyed by asset root.
   */
  private static array $projectCatalogs = [];

  /**
   * @param array<string, Skill> $skills The skills, keyed by name, in authored order.
   * @param array<string, string> $sourceFiles The data file each skill is authored in, keyed by name.
   * @param list<string> $problems The authoring problems found while reading the catalogue.
   */
  private function __construct(
    private readonly array $skills,
    private readonly array $sourceFiles,
    private readonly array $problems,
  )
  {
  }

  /**
   * Returns the running project's catalogue, read once per asset root.
   *
   * Its problems are reported once, when it is first read.
   *
   * @return self The catalogue.
   */
  public static function getProjectCatalog(): self
  {
    $assetRoot = Path::join(Path::getCurrentWorkingDirectory(), 'assets');

    if (! isset(self::$projectCatalogs[$assetRoot])) {
      $catalog = self::load($assetRoot);

      foreach ($catalog->getProblems() as $problem) {
        Debug::warn(sprintf('Skill catalogue: %s', $problem));
      }

      self::$projectCatalogs[$assetRoot] = $catalog;
    }

    return self::$projectCatalogs[$assetRoot];
  }

  /**
   * Reads a project's skill records, reporting each problem as a warning,
   * and returns the skills. This is what a project's `skills.php` barrel
   * returns.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return list<Skill> The skills, in file order.
   */
  public static function loadProjectSkills(string $assetRoot): array
  {
    $catalog = self::load($assetRoot);

    foreach ($catalog->getProblems() as $problem) {
      Debug::warn(sprintf('Skill records: %s', $problem));
    }

    return array_values($catalog->getSkills());
  }

  /**
   * Reads the catalogue from a project's asset root: every record file under
   * `Data/Skills`, in file name order, which is the order menus list skills
   * in (record files are numbered for it, as `0001-attack.php`). A file that
   * cannot be read is reported against that file and leaves the other skills
   * loaded.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return self The catalogue.
   */
  public static function load(string $assetRoot): self
  {
    $skills = [];
    $problems = [];
    $filenames = glob(Path::join($assetRoot, 'Data', self::DIRECTORY, '*.php')) ?: [];
    sort($filenames, SORT_STRING);

    foreach ($filenames as $filename) {
      $file = self::DIRECTORY . '/' . basename($filename);

      try {
        $skills[$file] = self::readRecord($filename);
      } catch (Throwable $exception) {
        $problems[] = sprintf('%s: %s', $file, $exception->getMessage());
      }
    }

    return self::fromSkills($skills, $problems);
  }

  /**
   * Builds the catalogue from skills keyed by the file that authors each, in
   * order. A name already taken is reported against the later file, which is
   * skipped.
   *
   * @param array<string, Skill> $skills The skills, keyed by their file relative to `assets/Data`.
   * @param list<string> $problems Problems already found while reading the files.
   * @return self The catalogue.
   */
  public static function fromSkills(array $skills, array $problems = []): self
  {
    $byName = [];
    $sourceFiles = [];

    foreach ($skills as $file => $skill) {
      if (isset($byName[$skill->name])) {
        $problems[] = sprintf(
          '%s: "%s" is already defined by %s; skill names must be unique, so this file is skipped.',
          $file,
          $skill->name,
          $sourceFiles[$skill->name],
        );
        continue;
      }

      $byName[$skill->name] = $skill;
      $sourceFiles[$skill->name] = $file;
    }

    return new self($byName, $sourceFiles, $problems);
  }
  /**
   * Returns every skill, keyed by name, in file order.
   *
   * @return array<string, Skill> The skills.
   */
  public function getSkills(): array
  {
    return $this->skills;
  }

  /**
   * Finds a skill by name.
   *
   * @param string $name The skill name.
   * @return Skill|null The skill, if the catalogue has one by that name.
   */
  public function findSkill(string $name): ?Skill
  {
    return $this->skills[$name] ?? null;
  }

  /**
   * Returns the spells, keyed by name.
   *
   * @return array<string, MagicSkill> The spells.
   */
  public function getSpells(): array
  {
    return array_filter($this->skills, static fn(Skill $skill): bool => $skill instanceof MagicSkill);
  }

  /**
   * Returns the special abilities, keyed by name.
   *
   * @return array<string, SpecialSkill> The abilities.
   */
  public function getAbilities(): array
  {
    return array_filter($this->skills, static fn(Skill $skill): bool => $skill instanceof SpecialSkill);
  }

  /**
   * Returns the record file a skill is authored in.
   *
   * @param string $name The skill name.
   * @return string|null The file name, relative to `assets/Data`.
   */
  public function getSourceFile(string $name): ?string
  {
    return $this->sourceFiles[$name] ?? null;
  }

  /**
   * Reads one record file.
   *
   * @throws InvalidArgumentException When the file is not a skill record.
   */
  private static function readRecord(string $filename): Skill
  {
    $payload = require $filename;

    if (! is_array($payload) || ($payload['class'] ?? null) !== Skill::class || ! is_array($payload['data'] ?? null)) {
      throw new InvalidArgumentException("a skill record returns ['class' => Skill::class, 'data' => [...]].");
    }

    return SkillRecord::readSkill($payload['data']);
  }

  /**
   * Returns the authoring problems found while reading the catalogue.
   *
   * @return list<string> The problems.
   */
  public function getProblems(): array
  {
    return $this->problems;
  }
}
