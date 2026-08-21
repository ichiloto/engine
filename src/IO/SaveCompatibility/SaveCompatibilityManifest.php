<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;
use Ichiloto\Engine\Exceptions\TombstonedSaveReferenceException;
use JsonException;
use Throwable;

/**
 * The project-owned compatibility contract used while loading save files.
 */
final readonly class SaveCompatibilityManifest
{
  /**
   * @param array<string, array<string, string>> $aliases
   * @param array<string, array<string, true>> $tombstones
   * @param array<int, class-string<ContentMigrationInterface>> $migrations
   */
  private function __construct(
    public string $projectId,
    public int $contentVersion,
    private array $aliases,
    private array $tombstones,
    private array $migrations,
  )
  {
  }

  /**
   * Loads the canonical project id and PHP compatibility manifest.
   */
  public static function fromProjectRoot(string $projectRoot): self
  {
    $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
    $projectPath = $projectRoot . DIRECTORY_SEPARATOR . 'ichiloto.json';
    $manifestPath = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Data'
      . DIRECTORY_SEPARATOR . 'save-compatibility.php';

    if (! is_file($projectPath)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        'Cannot establish save identity: %s does not exist.',
        $projectPath
      ));
    }

    try {
      $project = json_decode((string) file_get_contents($projectPath), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
      throw new InvalidSaveCompatibilityManifestException(
        sprintf('Cannot parse project identity from %s: %s', $projectPath, $exception->getMessage()),
        previous: $exception
      );
    }

    $projectId = is_array($project) ? trim(strval($project['id'] ?? '')) : '';

    if ($projectId === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        'Project save identity is missing: add a stable non-empty "id" to %s.',
        $projectPath
      ));
    }

    if (! is_file($manifestPath)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        'Save compatibility manifest not found: %s.',
        $manifestPath
      ));
    }

    try {
      $data = require $manifestPath;
    } catch (Throwable $throwable) {
      throw new InvalidSaveCompatibilityManifestException(
        sprintf('Could not load save compatibility manifest %s: %s', $manifestPath, $throwable->getMessage()),
        previous: $throwable
      );
    }

    if (! is_array($data)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        'Save compatibility manifest %s must return an array.',
        $manifestPath
      ));
    }

    return self::fromArray($projectId, $data, $manifestPath);
  }

  /**
   * Creates a manifest from already decoded project data.
   *
   * @param array<string, mixed> $data
   */
  public static function fromArray(string $projectId, array $data, string $source = 'save compatibility manifest'): self
  {
    $projectId = trim($projectId);

    if ($projectId === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s has an empty project id.', $source));
    }

    $rawVersion = $data['contentVersion'] ?? null;

    if (! is_int($rawVersion) || $rawVersion < 0) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s contentVersion must be a non-negative integer.',
        $source
      ));
    }

    $aliases = self::normalizeAliases($data['aliases'] ?? [], $source);
    $tombstones = self::normalizeTombstones($data['tombstones'] ?? [], $source);
    self::validateAliasGraph($aliases, $tombstones, $source);
    $migrations = self::normalizeMigrations($data['migrations'] ?? [], $rawVersion, $source);

    return new self($projectId, $rawVersion, $aliases, $tombstones, $migrations);
  }

  /**
   * @return class-string<ContentMigrationInterface>|null
   */
  public function migrationFrom(int $version): ?string
  {
    return $this->migrations[$version] ?? null;
  }

  /**
   * Resolves an explicitly aliased saved identity or fails on a tombstone.
   */
  public function resolve(ContentReferenceCategory $category, string $identity, string $savePath): string
  {
    $identity = trim($identity);

    if ($identity === '') {
      return '';
    }

    $categoryName = $category->value;
    $aliases = $this->aliases[$categoryName] ?? [];
    $tombstones = $this->tombstones[$categoryName] ?? [];
    $current = $identity;

    while (isset($aliases[$current])) {
      $current = $aliases[$current];
    }

    if (isset($tombstones[$current]) || isset($tombstones[$identity])) {
      throw new TombstonedSaveReferenceException(sprintf(
        'Save %s references tombstoned %s identity "%s"; a project-defined content migration is required.',
        $savePath,
        $categoryName,
        $identity
      ));
    }

    return $current;
  }

  /**
   * Resolves an event identity, applying both an explicit event alias and a
   * map alias to the map portion without changing the marker.
   */
  public function resolveOneShotEvent(string $identity, string $savePath): string
  {
    $identity = $this->resolve(ContentReferenceCategory::ONE_SHOT_EVENT, $identity, $savePath);
    [$mapId, $marker] = self::splitOneShotEventIdentity($identity, 'saved one-shot event identity');
    $mapId = $this->resolve(ContentReferenceCategory::MAP, $mapId, $savePath);

    return "{$mapId}:{$marker}";
  }

  /** @return array<string, string> */
  public function aliasesFor(ContentReferenceCategory $category): array
  {
    return $this->aliases[$category->value] ?? [];
  }

  /** @return string[] */
  public function tombstonesFor(ContentReferenceCategory $category): array
  {
    return array_keys($this->tombstones[$category->value] ?? []);
  }

  /**
   * @param mixed $rawAliases
   * @return array<string, array<string, string>>
   */
  private static function normalizeAliases(mixed $rawAliases, string $source): array
  {
    if (! is_array($rawAliases)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s aliases must be an array.', $source));
    }

    $normalized = [];

    foreach ($rawAliases as $categoryName => $entries) {
      $category = is_string($categoryName) ? ContentReferenceCategory::tryFrom($categoryName) : null;

      if (! $category instanceof ContentReferenceCategory) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s aliases contain invalid category "%s".',
          $source,
          strval($categoryName)
        ));
      }

      if (! is_array($entries)) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s aliases.%s must be a list.',
          $source,
          $category->value
        ));
      }

      foreach ($entries as $index => $entry) {
        if (! is_array($entry)) {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s aliases.%s[%s] must contain from and to strings.',
            $source,
            $category->value,
            strval($index)
          ));
        }

        $from = trim(strval($entry['from'] ?? ''));
        $to = trim(strval($entry['to'] ?? ''));

        if ($from === '' || $to === '') {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s aliases.%s[%s] requires non-empty from and to values.',
            $source,
            $category->value,
            strval($index)
          ));
        }

        if ($category === ContentReferenceCategory::ONE_SHOT_EVENT) {
          self::splitOneShotEventIdentity($from, "{$source} aliases.{$category->value}[{$index}].from");
          self::splitOneShotEventIdentity($to, "{$source} aliases.{$category->value}[{$index}].to");
        }

        if ($from === $to) {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s aliases.%s contains self-alias "%s".',
            $source,
            $category->value,
            $from
          ));
        }

        if (isset($normalized[$category->value][$from]) && $normalized[$category->value][$from] !== $to) {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s aliases.%s maps "%s" to both "%s" and "%s".',
            $source,
            $category->value,
            $from,
            $normalized[$category->value][$from],
            $to
          ));
        }

        $normalized[$category->value][$from] = $to;
      }
    }

    return $normalized;
  }

  /**
   * @param mixed $rawTombstones
   * @return array<string, array<string, true>>
   */
  private static function normalizeTombstones(mixed $rawTombstones, string $source): array
  {
    if (! is_array($rawTombstones)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s tombstones must be an array.', $source));
    }

    $normalized = [];

    foreach ($rawTombstones as $categoryName => $entries) {
      $category = is_string($categoryName) ? ContentReferenceCategory::tryFrom($categoryName) : null;

      if (! $category instanceof ContentReferenceCategory) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s tombstones contain invalid category "%s".',
          $source,
          strval($categoryName)
        ));
      }

      if (! is_array($entries)) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s tombstones.%s must be a list.',
          $source,
          $category->value
        ));
      }

      foreach ($entries as $index => $entry) {
        $identity = is_string($entry) ? trim($entry) : '';

        if ($identity === '') {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s tombstones.%s[%s] must be a non-empty string.',
            $source,
            $category->value,
            strval($index)
          ));
        }

        if ($category === ContentReferenceCategory::ONE_SHOT_EVENT) {
          self::splitOneShotEventIdentity($identity, "{$source} tombstones.{$category->value}[{$index}]");
        }

        $normalized[$category->value][$identity] = true;
      }
    }

    return $normalized;
  }

  /**
   * @param array<string, array<string, string>> $aliases
   * @param array<string, array<string, true>> $tombstones
   */
  private static function validateAliasGraph(array $aliases, array $tombstones, string $source): void
  {
    foreach ($aliases as $category => $entries) {
      foreach ($entries as $from => $to) {
        if (isset($tombstones[$category][$from])) {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s lists %s identity "%s" as both an alias source and a tombstone.',
            $source,
            $category,
            $from
          ));
        }

        $seen = [$from => true];
        $current = $to;

        while (isset($entries[$current])) {
          if (isset($seen[$current])) {
            throw new InvalidSaveCompatibilityManifestException(sprintf(
              '%s aliases.%s contains a cycle involving "%s".',
              $source,
              $category,
              $current
            ));
          }

          $seen[$current] = true;
          $current = $entries[$current];
        }
      }
    }
  }

  /**
   * @param mixed $rawMigrations
   * @return array<int, class-string<ContentMigrationInterface>>
   */
  private static function normalizeMigrations(mixed $rawMigrations, int $contentVersion, string $source): array
  {
    if (! is_array($rawMigrations)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s migrations must be a list.', $source));
    }

    $normalized = [];
    $previousFrom = -1;

    foreach ($rawMigrations as $index => $entry) {
      if (! is_array($entry) || ! is_int($entry['from'] ?? null) || ! is_int($entry['to'] ?? null)) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s migrations[%s] requires integer from and to versions.',
          $source,
          strval($index)
        ));
      }

      $from = $entry['from'];
      $to = $entry['to'];
      $class = is_string($entry['class'] ?? null) ? trim($entry['class']) : '';

      if ($from < 0 || $to !== $from + 1) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s migrations[%s] must describe one non-negative adjacent step.',
          $source,
          strval($index)
        ));
      }

      if (isset($normalized[$from])) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s registers duplicate migration step %d to %d.',
          $source,
          $from,
          $to
        ));
      }

      if ($from <= $previousFrom) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s migrations are registered in an impossible order near step %d to %d.',
          $source,
          $from,
          $to
        ));
      }

      if ($class === '' || ! is_subclass_of($class, ContentMigrationInterface::class)) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s migrations[%s] class "%s" must implement %s.',
          $source,
          strval($index),
          $class,
          ContentMigrationInterface::class
        ));
      }

      $normalized[$from] = $class;
      $previousFrom = $from;
    }

    if ($previousFrom >= $contentVersion) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s registers migrations beyond current contentVersion %d.',
        $source,
        $contentVersion
      ));
    }

    return $normalized;
  }

  /** @return array{string, string} */
  private static function splitOneShotEventIdentity(string $identity, string $where): array
  {
    if (substr_count($identity, ':') !== 1) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must use exact mapId:marker syntax.',
        $where
      ));
    }

    [$mapId, $marker] = array_map('trim', explode(':', $identity, 2));

    if ($mapId === '' || $marker === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must use non-empty mapId:marker syntax.',
        $where
      ));
    }

    return [$mapId, $marker];
  }
}
