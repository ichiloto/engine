<?php

namespace Ichiloto\Engine\Entities\Enemies;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/**
 * The project's enemies, authored one record per file.
 *
 * Each file under `assets/Data/Enemies` returns
 * `['class' => Enemy::class, 'data' => [...]]`, the data in the form
 * {@see EnemyRecord} reads. A file that cannot be read is reported against
 * that file and leaves the other enemies loaded. Enemy names are the identity
 * troops and the bestiary reference, so a second file using a name already
 * taken is reported and skipped.
 *
 * @package Ichiloto\Engine\Entities\Enemies
 */
final class EnemyCatalog
{
  /** The record folder, relative to `assets/Data`. */
  public const string DIRECTORY = 'Enemies';

  /**
   * @param array<string, Enemy> $enemies The enemies, keyed by name, in file order.
   * @param array<string, string> $sourceFiles Each enemy's record file, relative to `assets/Data`, keyed by name.
   * @param list<string> $problems The authoring problems found while reading the records.
   */
  private function __construct(
    private readonly array $enemies,
    private readonly array $sourceFiles,
    private readonly array $problems,
  )
  {
  }

  /**
   * Reads a project's enemy records, reporting each problem as a warning,
   * and returns the enemies. This is what a project's `enemies.php` barrel
   * returns.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return list<Enemy> The enemies, in file order.
   */
  public static function loadProjectEnemies(string $assetRoot): array
  {
    $catalog = self::load($assetRoot);

    foreach ($catalog->getProblems() as $problem) {
      Debug::warn(sprintf('Enemy records: %s', $problem));
    }

    return array_values($catalog->getEnemies());
  }

  /**
   * Reads a project's enemy records.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @param SkillCatalog|null $skills The skill catalogue patterns name skills in; the project's own when omitted.
   * @return self The catalogue.
   */
  public static function load(string $assetRoot, ?SkillCatalog $skills = null): self
  {
    $skills ??= SkillCatalog::load($assetRoot);
    $enemies = [];
    $sourceFiles = [];
    $problems = [];
    $directory = Path::join($assetRoot, 'Data', self::DIRECTORY);

    foreach (glob(Path::join($directory, '*.php')) ?: [] as $filename) {
      $file = self::DIRECTORY . '/' . basename($filename);

      try {
        $enemy = self::readRecord($filename, $skills);
      } catch (Throwable $exception) {
        $problems[] = sprintf('%s: %s', $file, $exception->getMessage());
        continue;
      }

      if (isset($enemies[$enemy->name])) {
        $problems[] = sprintf(
          '%s: "%s" is already defined by %s; enemy names must be unique, so this file is skipped.',
          $file,
          $enemy->name,
          $sourceFiles[$enemy->name],
        );
        continue;
      }

      $enemies[$enemy->name] = $enemy;
      $sourceFiles[$enemy->name] = $file;
    }

    return new self($enemies, $sourceFiles, $problems);
  }

  /**
   * Returns every enemy, keyed by name, in file order.
   *
   * @return array<string, Enemy> The enemies.
   */
  public function getEnemies(): array
  {
    return $this->enemies;
  }

  /**
   * Finds an enemy by name.
   *
   * @param string $name The enemy name.
   * @return Enemy|null The enemy, if one is authored by that name.
   */
  public function findEnemy(string $name): ?Enemy
  {
    return $this->enemies[$name] ?? null;
  }

  /**
   * Returns the record file an enemy is authored in.
   *
   * @param string $name The enemy name.
   * @return string|null The file, relative to `assets/Data`.
   */
  public function getSourceFile(string $name): ?string
  {
    return $this->sourceFiles[$name] ?? null;
  }

  /**
   * Returns the authoring problems found while reading the records.
   *
   * @return list<string> The problems, each naming its file.
   */
  public function getProblems(): array
  {
    return $this->problems;
  }

  private static function readRecord(string $filename, SkillCatalog $skills): Enemy
  {
    $payload = require $filename;

    if (! is_array($payload) || ($payload['class'] ?? null) !== Enemy::class || ! is_array($payload['data'] ?? null)) {
      throw new InvalidArgumentException("an enemy record returns ['class' => Enemy::class, 'data' => [...]].");
    }

    return EnemyRecord::readEnemy($payload['data'], $skills);
  }
}
