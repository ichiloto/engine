<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ClassStore;
use InvalidArgumentException;
use Throwable;

/**
 * A project's Mastery Maps: the mastery tree every character shares (its
 * regions, branches and nodes), declared in `assets/Data/mastery.php`, and
 * the terms each character's own map states in `assets/Data/Mastery/*.php`.
 * Every character reaches the whole tree; a character without a map meets it
 * on the tree's terms. A project with no tree nodes has no Mastery Maps, and
 * its characters learn only through their roles and books.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryCatalog
{
  public const string SETTINGS = 'Data/mastery.php';
  public const string DIRECTORY = 'Data/Mastery';

  /** @var array<string, self> */
  private static array $projectCatalogs = [];

  /** The regions, in the order maps show them. */
  public array $regions {
    get => $this->tree->regions;
  }

  /**
   * @param MasteryTree $tree The mastery tree every character shares.
   * @param array<string, MasteryMap> $maps The characters whose map files state their own terms, by actor id.
   * @param list<string> $problems What could not be read, in words.
   */
  public function __construct(
    public readonly MasteryTree $tree = new MasteryTree(),
    private readonly array $maps = [],
    public readonly array $problems = [],
  )
  {
  }

  /** The catalogue of the project the game runs from, read once. */
  public static function getProjectCatalog(): self
  {
    $assetRoot = Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    if (! isset(self::$projectCatalogs[$assetRoot])) {
      $catalog = self::load($assetRoot, SkillCatalog::getProjectCatalog());
      foreach ([...$catalog->problems, ...$catalog->findCurriculumProblems(ClassStore::all())] as $problem) {
        Debug::warn(sprintf('Mastery maps: %s', $problem));
      }
      self::$projectCatalogs[$assetRoot] = $catalog;
    }

    return self::$projectCatalogs[$assetRoot];
  }

  /** Forgets catalogues read so far, so the next read sees changed files. */
  public static function forgetProjectCatalogs(): void
  {
    self::$projectCatalogs = [];
  }

  /**
   * Reads a project's tree and maps. A tree that cannot be read, or that
   * teaches a skill the project has neither as a skill nor as a draft,
   * leaves the project without Mastery Maps and is reported; a map that
   * cannot be read is left out and reported, and its character meets the
   * tree on its terms.
   */
  public static function load(string $assetRoot, ?SkillCatalog $skills = null): self
  {
    $problems = [];
    $tree = new MasteryTree();
    $settings = Path::join($assetRoot, self::SETTINGS);
    if (is_file($settings)) {
      try {
        $data = require $settings;
        $read = MasteryTree::fromArray(is_array($data) ? $data : []);
        foreach ($read->nodes as $node) {
          if ($skills !== null && $node->skill !== null && $skills->findSkill($node->skill) === null && $skills->findDraft($node->skill) === null) {
            throw new InvalidArgumentException(sprintf('node %s teaches unknown skill %s', $node->id, $node->skill));
          }
        }
        $tree = $read;
      } catch (Throwable $error) {
        $problems[] = sprintf('%s: %s', self::SETTINGS, $error->getMessage());
      }
    }
    $maps = [];
    foreach (glob(Path::join($assetRoot, self::DIRECTORY, '*.php')) ?: [] as $file) {
      try {
        $data = require $file;
        $map = MasteryMap::fromArray(is_array($data) ? $data : [], $tree);
        if (isset($maps[$map->actorId])) {
          throw new InvalidArgumentException(sprintf('%s already has a mastery map', $map->actorId));
        }
        $maps[$map->actorId] = $map;
      } catch (Throwable $error) {
        $problems[] = sprintf('%s: %s', basename($file), $error->getMessage());
      }
    }

    return new self($tree, $maps, $problems);
  }

  /**
   * What is wrong with the roles' curricula against the tree: each curriculum entry naming a node the
   * tree does not have. A curriculum only guides, so such an entry is reported and never shown.
   *
   * @param array<string, array<string, mixed>> $classes The project's class definitions (`classes.php`), by name.
   * @return list<string>
   */
  public function findCurriculumProblems(array $classes): array
  {
    $problems = [];
    foreach ($classes as $class) {
      foreach (is_array($class['curriculum'] ?? null) ? $class['curriculum'] : [] as $node) {
        if (is_string($node) && $this->tree->findNode($node) === null) {
          $problems[] = sprintf('classes.php: %s\'s curriculum names node %s, which the mastery tree does not have', strval($class['name'] ?? ''), $node);
        }
      }
    }

    return $problems;
  }

  /** Whether the project has a tree to learn from. */
  public function hasNodes(): bool
  {
    return $this->tree->nodes !== [];
  }

  /**
   * A character's map: the tree on their own terms where their map file
   * states any, and on the tree's terms otherwise. Null when the project has
   * no tree.
   */
  public function findMap(string $actorId): ?MasteryMap
  {
    if (! $this->hasNodes() || trim($actorId) === '') {
      return null;
    }

    return $this->maps[$actorId] ?? new MasteryMap($actorId, [], $this->tree);
  }

  /** @return array<string, MasteryMap> The maps whose files state a character's own terms, by actor id. */
  public function getMaps(): array
  {
    return $this->maps;
  }
}
