<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use ErrorException;
use Ichiloto\Engine\Exceptions\CorruptSaveException;
use Ichiloto\Engine\Exceptions\MissingSaveMigrationException;
use Ichiloto\Engine\Exceptions\SaveCompatibilityException;
use Ichiloto\Engine\Exceptions\SaveMigrationException;
use Ichiloto\Engine\Exceptions\UnsupportedContentVersionException;
use Ichiloto\Engine\Exceptions\UnsupportedSaveSchemaException;
use Ichiloto\Engine\Exceptions\WrongProjectSaveException;
use Ichiloto\Engine\IO\Saves\SavedGame;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Throwable;

/**
 * Runs save-schema migration, game migration, and content resolution in the
 * one load path shared by normal, quick, autosave, and Continue surfaces.
 */
final readonly class SaveCompatibilityPipeline
{
  public const int CURRENT_SCHEMA_VERSION = 1;

  /** @var array<int, class-string<SaveSchemaMigrationInterface>> */
  private const array SCHEMA_MIGRATIONS = [
    0 => SchemaVersion0To1Migration::class,
  ];

  /** @var array<int, class-string<SaveSchemaMigrationInterface>> */
  private array $schemaMigrations;

  /**
   * @param array<int, class-string<SaveSchemaMigrationInterface>>|null $schemaMigrations
   */
  public function __construct(
    private SaveCompatibilityManifest $manifest,
    ?array $schemaMigrations = null,
  )
  {
    $this->schemaMigrations = $schemaMigrations ?? self::SCHEMA_MIGRATIONS;
  }

  /** @return array<string, mixed> */
  public function createEnvelope(SaveSlot $slot, GameConfig $config): array
  {
    return [
      'schemaVersion' => self::CURRENT_SCHEMA_VERSION,
      'contentVersion' => $this->manifest->contentVersion,
      'projectId' => $this->manifest->projectId,
      'payload' => [
        'slot' => $slot,
        'config' => $config,
      ],
    ];
  }

  public function load(string $serializedPayload, string $savePath): SavedGame
  {
    $decoded = $this->unserializePayload($serializedPayload, $savePath);
    $envelope = $this->detectEnvelope($decoded, $savePath);
    $schemaVersion = $envelope['schemaVersion'];

    if ($schemaVersion > self::CURRENT_SCHEMA_VERSION) {
      throw new UnsupportedSaveSchemaException(sprintf(
        'Save %s uses schema version %d, but this engine supports at most version %d; a newer engine is required.',
        $savePath,
        $schemaVersion,
        self::CURRENT_SCHEMA_VERSION
      ));
    }

    $declaredProjectId = $envelope['projectId'];

    if (is_string($declaredProjectId) && $declaredProjectId !== '' && $declaredProjectId !== $this->manifest->projectId) {
      $this->throwWrongProject($declaredProjectId, $savePath);
    }

    while ($schemaVersion < self::CURRENT_SCHEMA_VERSION) {
      $migrationClass = $this->schemaMigrations[$schemaVersion] ?? null;

      if ($migrationClass === null) {
        throw new MissingSaveMigrationException(sprintf(
          'Save %s requires missing schema migration %d to %d.',
          $savePath,
          $schemaVersion,
          $schemaVersion + 1
        ));
      }

      try {
        $envelope = new $migrationClass()->migrate($envelope, $this->manifest->projectId);
      } catch (SaveCompatibilityException $exception) {
        throw $exception;
      } catch (Throwable $throwable) {
        throw new SaveMigrationException(sprintf(
          'Schema migration %d to %d failed for save %s: %s',
          $schemaVersion,
          $schemaVersion + 1,
          $savePath,
          $throwable->getMessage()
        ), previous: $throwable);
      }

      $schemaVersion++;
      $envelope['schemaVersion'] = $schemaVersion;
    }

    $declaredProjectId = $envelope['projectId'] ?? '';

    if (! is_string($declaredProjectId) || trim($declaredProjectId) === '') {
      throw new CorruptSaveException(sprintf('Save %s has no project identity after schema migration.', $savePath));
    }

    if ($declaredProjectId !== $this->manifest->projectId) {
      $this->throwWrongProject($declaredProjectId, $savePath);
    }

    $contentVersion = $envelope['contentVersion'];

    if ($contentVersion > $this->manifest->contentVersion) {
      throw new UnsupportedContentVersionException(sprintf(
        'Save %s uses game content version %d, but project %s supports at most version %d; a newer game build is required.',
        $savePath,
        $contentVersion,
        $this->manifest->projectId,
        $this->manifest->contentVersion
      ));
    }

    $postResolutionMigrations = [];

    while ($contentVersion < $this->manifest->contentVersion) {
      $migrationClass = $this->manifest->migrationFrom($contentVersion);

      if ($migrationClass === null) {
        throw new MissingSaveMigrationException(sprintf(
          'Save %s requires missing content migration %d to %d for project %s.',
          $savePath,
          $contentVersion,
          $contentVersion + 1,
          $this->manifest->projectId
        ));
      }

      try {
        $migration = new $migrationClass();
        $payload = $migration->migrate($envelope['payload']);

        if ($migration instanceof PostResolutionContentMigrationInterface) {
          $postResolutionMigrations[] = $migration;
        }
      } catch (SaveCompatibilityException $exception) {
        throw $exception;
      } catch (Throwable $throwable) {
        throw new SaveMigrationException(sprintf(
          'Content migration %d to %d failed for save %s: %s',
          $contentVersion,
          $contentVersion + 1,
          $savePath,
          $throwable->getMessage()
        ), previous: $throwable);
      }

      if (! is_array($payload)) {
        throw new SaveMigrationException(sprintf(
          'Content migration %d to %d returned an invalid payload for save %s.',
          $contentVersion,
          $contentVersion + 1,
          $savePath
        ));
      }

      $envelope['payload'] = $payload;
      $contentVersion++;
      $envelope['contentVersion'] = $contentVersion;
    }

    $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
    $slot = $payload['slot'] ?? null;
    $config = $payload['config'] ?? null;

    if (! $slot instanceof SaveSlot || ! $config instanceof GameConfig) {
      throw new CorruptSaveException(sprintf('Save file payload is incomplete: %s', $savePath));
    }

    $config = new SaveContentResolver($this->manifest, $savePath)->resolve($config);

    foreach ($postResolutionMigrations as $migration) {
      try {
        $migration->migrateResolved($config);
      } catch (SaveCompatibilityException $exception) {
        throw $exception;
      } catch (Throwable $throwable) {
        throw new SaveMigrationException(sprintf(
          'Post-resolution content migration failed for save %s: %s',
          $savePath,
          $throwable->getMessage()
        ), previous: $throwable);
      }
    }

    return new SavedGame($slot, $config);
  }

  private function unserializePayload(string $serializedPayload, string $savePath): mixed
  {
    SaveHydrationContext::begin();
    set_error_handler(static function (int $severity, string $message): never {
      throw new ErrorException($message, 0, $severity);
    });

    try {
      return unserialize($serializedPayload, ['allowed_classes' => true]);
    } catch (Throwable $throwable) {
      throw new CorruptSaveException(sprintf(
        'Could not unserialize save %s: %s',
        $savePath,
        $throwable->getMessage()
      ), previous: $throwable);
    } finally {
      restore_error_handler();
      SaveHydrationContext::end();
    }
  }

  /**
   * @return array{schemaVersion: int, contentVersion: int, projectId: string|null, payload: array<string, mixed>}
   */
  private function detectEnvelope(mixed $decoded, string $savePath): array
  {
    if (! is_array($decoded)) {
      throw new CorruptSaveException(sprintf('Invalid save file payload: %s', $savePath));
    }

    $isVersioned = array_key_exists('schemaVersion', $decoded)
      || array_key_exists('contentVersion', $decoded)
      || array_key_exists('projectId', $decoded)
      || array_key_exists('payload', $decoded);

    if (! $isVersioned) {
      if (! array_key_exists('slot', $decoded) || ! array_key_exists('config', $decoded)) {
        throw new CorruptSaveException(sprintf('Unrecognized legacy save payload: %s', $savePath));
      }

      return [
        'schemaVersion' => 0,
        'contentVersion' => 0,
        'projectId' => null,
        'payload' => $decoded,
      ];
    }

    if (! is_int($decoded['schemaVersion'] ?? null) || $decoded['schemaVersion'] < 0) {
      throw new CorruptSaveException(sprintf('Save %s has an invalid schemaVersion.', $savePath));
    }

    if (! is_int($decoded['contentVersion'] ?? null) || $decoded['contentVersion'] < 0) {
      throw new CorruptSaveException(sprintf('Save %s has an invalid contentVersion.', $savePath));
    }

    if (! is_string($decoded['projectId'] ?? null) || trim($decoded['projectId']) === '') {
      throw new CorruptSaveException(sprintf('Save %s has an invalid projectId.', $savePath));
    }

    if (! is_array($decoded['payload'] ?? null)) {
      throw new CorruptSaveException(sprintf('Save %s has an invalid versioned payload.', $savePath));
    }

    return [
      'schemaVersion' => $decoded['schemaVersion'],
      'contentVersion' => $decoded['contentVersion'],
      'projectId' => trim($decoded['projectId']),
      'payload' => $decoded['payload'],
    ];
  }

  private function throwWrongProject(string $actualProjectId, string $savePath): never
  {
    throw new WrongProjectSaveException(sprintf(
      'Save %s belongs to project "%s", not current project "%s".',
      $savePath,
      $actualProjectId,
      $this->manifest->projectId
    ));
  }
}
