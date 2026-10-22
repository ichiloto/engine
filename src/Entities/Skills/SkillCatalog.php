<?php

namespace Ichiloto\Engine\Entities\Skills;

use Assegai\Util\Path;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
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
 * Catalogue membership defines a resource, not permission to use it. Actors
 * select their inherent attack and own learned skills; enemies own action patterns.
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
   * @param array<string, true> $summonActions Linked action identities from this project's summon definitions.
   * @param array<string, SkillDraft> $drafts The records still awaiting author values, keyed by name.
   */
  private function __construct(
    private readonly array $skills,
    private readonly array $sourceFiles,
    private readonly array $problems,
    private readonly array $summonActions,
    private readonly array $drafts = [],
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
   * loaded. A record that still awaits author values is kept as a draft: its
   * name is taken, but it is not a skill anyone can use (see SkillDraft).
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return self The catalogue.
   */
  public static function load(string $assetRoot): self
  {
    $skills = [];
    $drafts = [];
    $problems = [];
    $filenames = glob(Path::join($assetRoot, 'Data', self::DIRECTORY, '*.php')) ?: [];
    sort($filenames, SORT_STRING);

    foreach ($filenames as $filename) {
      $file = self::DIRECTORY . '/' . basename($filename);

      try {
        $record = self::readRecord($filename);
        if ($record instanceof SkillDraft) {
          $drafts[$file] = $record;
        } else {
          $skills[$file] = $record;
        }
      } catch (Throwable $exception) {
        $problems[] = sprintf('%s: %s', $file, $exception->getMessage());
      }
    }

    return self::fromSkills($skills, $problems,
      new SummonCutsceneLibrary(Path::join($assetRoot, 'Cutscenes', 'Summons'))->load(), $drafts);
  }

  /**
   * Builds the catalogue from skills keyed by the file that authors each, in
   * order. A name already taken is reported against the later file, which is
   * skipped.
   *
   * @param array<string, Skill> $skills The skills, keyed by their file relative to `assets/Data`.
   * @param list<string> $problems Problems already found while reading the files.
   * @param list<SummonCutsceneDefinition> $summons Definitions in the same project, not an active battle's global registry.
   * @param array<string, SkillDraft> $drafts Records still awaiting author values, keyed by their file; their names are taken too.
   * @return self The catalogue.
   */
  public static function fromSkills(array $skills, array $problems = [], array $summons = [], array $drafts = []): self
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

    $draftsByName = [];
    foreach ($drafts as $file => $draft) {
      if (isset($sourceFiles[$draft->name])) {
        $problems[] = sprintf(
          '%s: "%s" is already defined by %s; skill names must be unique, so this file is skipped.',
          $file,
          $draft->name,
          $sourceFiles[$draft->name],
        );
        continue;
      }

      $draftsByName[$draft->name] = $draft;
      $sourceFiles[$draft->name] = $file;
    }

    $summonActions = [];
    foreach ($summons as $summon) {
      if ($summon->linkedActionId !== null) { $summonActions[$summon->linkedActionId] = true; }
    }
    return new self($byName, $sourceFiles, $problems, $summonActions, $draftsByName);
  }

  /** Classifies authored action identity independently of process working directory and battle lifetime. */
  public function isSummonAction(string $name): bool
  {
    return isset($this->summonActions[$name]);
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
   * Finds a record still awaiting author values, by name.
   *
   * @param string $name The skill name.
   * @return SkillDraft|null The draft, if a record by that name awaits values.
   */
  public function findDraft(string $name): ?SkillDraft
  {
    return $this->drafts[$name] ?? null;
  }

  /**
   * Returns the records still awaiting author values, keyed by name, in file order.
   *
   * @return array<string, SkillDraft> The drafts.
   */
  public function getDrafts(): array
  {
    return $this->drafts;
  }

  /**
   * Returns the record file a skill or draft is authored in.
   *
   * @param string $name The skill name.
   * @return string|null The file name, relative to `assets/Data`.
   */
  public function getSourceFile(string $name): ?string
  {
    return $this->sourceFiles[$name] ?? null;
  }

  /**
   * Reads one record's data: a skill, or a draft when it still lists values
   * awaiting its author.
   *
   * @param array<string, mixed> $data The record's data.
   * @throws InvalidArgumentException When the data is neither.
   */
  public static function readRecordData(array $data): Skill|SkillDraft
  {
    return SkillDraft::isDraft($data) ? SkillDraft::fromArray($data) : SkillRecord::readSkill($data);
  }

  /**
   * Reads one record file.
   *
   * @throws InvalidArgumentException When the file is not a skill record.
   */
  private static function readRecord(string $filename): Skill|SkillDraft
  {
    $payload = require $filename;

    if (! is_array($payload) || ($payload['class'] ?? null) !== Skill::class || ! is_array($payload['data'] ?? null)) {
      throw new InvalidArgumentException("a skill record returns ['class' => Skill::class, 'data' => [...]].");
    }

    return self::readRecordData($payload['data']);
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
