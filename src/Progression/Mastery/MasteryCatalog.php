<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/**
 * A project's Mastery Maps: the regions every map shares, declared in
 * `assets/Data/mastery.php`, and one map per character in
 * `assets/Data/Mastery/*.php`. A project with neither has no maps, and its
 * characters learn only through their roles and books.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryCatalog
{
  public const string SETTINGS = 'Data/mastery.php';
  public const string DIRECTORY = 'Data/Mastery';

  /** @var array<string, self> */
  private static array $projectCatalogs = [];

  /**
   * @param list<MasteryRegion> $regions The regions, in the order maps show them.
   * @param array<string, MasteryMap> $maps Each character's map, by actor id.
   * @param list<string> $problems What could not be read, in words.
   */
  public function __construct(
    public readonly array $regions = [],
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
      foreach ($catalog->problems as $problem) {
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
   * Reads a project's regions and maps. A map that cannot be read, or that
   * teaches a skill the project does not have, is left out and reported.
   */
  public static function load(string $assetRoot, ?SkillCatalog $skills = null): self
  {
    $problems = [];
    $regions = [];
    $settings = Path::join($assetRoot, self::SETTINGS);
    if (is_file($settings)) {
      try {
        $data = require $settings;
        foreach (is_array($data['regions'] ?? null) ? $data['regions'] : [] as $region) {
          $regions[] = MasteryRegion::fromArray(is_array($region) ? $region : []);
        }
      } catch (Throwable $error) {
        $problems[] = sprintf('%s: %s', self::SETTINGS, $error->getMessage());
      }
    }
    $regionIds = array_map(static fn(MasteryRegion $region): string => $region->id, $regions);
    $maps = [];
    foreach (glob(Path::join($assetRoot, self::DIRECTORY, '*.php')) ?: [] as $file) {
      try {
        $data = require $file;
        $map = MasteryMap::fromArray(is_array($data) ? $data : [], $regionIds);
        foreach ($map->nodes as $node) {
          if ($skills !== null && $skills->findSkill($node->skill) === null) {
            throw new InvalidArgumentException(sprintf('node %s teaches unknown skill %s', $node->id, $node->skill));
          }
        }
        if (isset($maps[$map->actorId])) {
          throw new InvalidArgumentException(sprintf('%s already has a mastery map', $map->actorId));
        }
        $maps[$map->actorId] = $map;
      } catch (Throwable $error) {
        $problems[] = sprintf('%s: %s', basename($file), $error->getMessage());
      }
    }

    return new self($regions, $maps, $problems);
  }

  public function findMap(string $actorId): ?MasteryMap
  {
    return $this->maps[$actorId] ?? null;
  }

  /** @return array<string, MasteryMap> */
  public function getMaps(): array
  {
    return $this->maps;
  }
}
