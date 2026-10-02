<?php

namespace Ichiloto\Engine\Entities\Skills;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * The project's skill catalogue: every skill the project authors, in one
 * identity space.
 *
 * A project may author skills across several data files. Which file a skill
 * lives in is authoring organisation only: a skill is identified by its name
 * across all of them, and its kind comes from its class (a MagicSkill is a
 * spell, a SpecialSkill an ability, a BasicSkill an attack). Every consumer
 * that resolves a skill by name reads this catalogue, so a skill is found the
 * same way wherever it is authored.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
final class SkillCatalog
{
  /**
   * The data files a project authors skills in, relative to `assets/Data`,
   * in the order their skills are listed.
   */
  public const array DATA_FILES = ['skills.php', 'abilities.php', 'magic.php'];

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
   * Reads the catalogue from a project's asset root.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return self The catalogue.
   */
  public static function load(string $assetRoot): self
  {
    $payloads = [];
    $problems = [];

    foreach (self::DATA_FILES as $file) {
      $filename = Path::join($assetRoot, 'Data', $file);

      if (! is_file($filename)) {
        continue;
      }

      try {
        $payloads[$file] = require $filename;
      } catch (Throwable $exception) {
        $problems[] = sprintf('%s could not be read: %s', $file, $exception->getMessage());
      }
    }

    return self::fromFiles($payloads, $problems);
  }

  /**
   * Builds the catalogue from the loaded contents of its data files.
   *
   * @param array<string, mixed> $payloads Each data file's returned value, keyed by file name.
   * @param list<string> $problems Problems already found while reading the files.
   * @return self The catalogue.
   */
  public static function fromFiles(array $payloads, array $problems = []): self
  {
    $skills = [];
    $sourceFiles = [];

    foreach ($payloads as $file => $payload) {
      if (! is_array($payload)) {
        $problems[] = sprintf('%s must return an array of skills.', $file);
        continue;
      }

      foreach ($payload as $key => $skill) {
        if (! $skill instanceof Skill) {
          $problems[] = sprintf('%s entry %s is not a skill.', $file, var_export($key, true));
          continue;
        }

        if (is_string($key) && $key !== $skill->name) {
          $problems[] = sprintf(
            '%s registers "%s" under the key "%s"; a skill is found by its name, so the key must match it.',
            $file,
            $skill->name,
            $key,
          );
        }

        if (isset($skills[$skill->name])) {
          $problems[] = sprintf(
            '"%s" is defined in both %s and %s; skill names must be unique, so the one in %s is used.',
            $skill->name,
            $sourceFiles[$skill->name],
            $file,
            $sourceFiles[$skill->name],
          );
          continue;
        }

        $skills[$skill->name] = $skill;
        $sourceFiles[$skill->name] = $file;
      }
    }

    return new self($skills, $sourceFiles, $problems);
  }

  /**
   * Returns every skill, keyed by name, in authored order.
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
   * Returns the data file a skill is authored in.
   *
   * @param string $name The skill name.
   * @return string|null The file name, relative to `assets/Data`.
   */
  public function getSourceFile(string $name): ?string
  {
    return $this->sourceFiles[$name] ?? null;
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
