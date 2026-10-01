<?php

namespace Ichiloto\Engine\Util\Stores;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use RuntimeException;

/** Project actor catalogue and authoritative character factory. */
final class ActorStore implements ConfigInterface
{
  /** @var array<string, ActorDefinition> */
  private array $definitions = [];
  /** @var array<string, list<string>> File references of missing-id definitions only. */
  private array $legacyStartingPartyIds = [];

  /** @param iterable<ActorDefinition>|null $definitions In-memory authoring registry, when supplied. */
  public function __construct(?string $directory = null, ?iterable $definitions = null)
  {
    if ($definitions !== null) {
      foreach ($definitions as $definition) {
        $this->register($definition);
      }
      return;
    }
    $directory ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'Actors');

    foreach (glob(Path::join($directory, '*.php')) ?: [] as $filename) {
      $payload = require $filename;

      if (! is_array($payload)) {
        throw new RuntimeException(sprintf('Actor definition %s must return an array.', $filename));
      }

      $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
      if (! array_key_exists('id', $data) && is_string($data['name'] ?? null) && trim($data['name']) !== '') {
        $data['id'] = trim($data['name']);
        $this->legacyStartingPartyIds[self::normalize(pathinfo($filename, PATHINFO_FILENAME))][] = $data['id'];
        Debug::warn(sprintf(
          'Legacy actor %s has no explicit id; using provisional id "%s" in memory only. Freeze the current name as data.id using the Editor actor identity repair or CLI validation migration before renaming. No project file was changed.',
          $filename, $data['id'],
        ));
      }
      $definition = ActorDefinition::fromArray($data, $filename);
      $this->register($definition);
    }
  }

  public function get(string $path, mixed $default = null): ?ActorDefinition
  {
    $id = $this->resolveId($path);
    $definition = $id !== null ? $this->definitions[$id] ?? null : null;

    return $definition ?? ($default instanceof ActorDefinition ? $default : null);
  }

  public function require(string $reference, string $context): ActorDefinition
  {
    $definition = $this->get($reference);

    if (! $definition instanceof ActorDefinition) {
      throw new UnresolvedSaveReferenceException(sprintf(
        'Actor definition "%s" cannot be resolved while %s.',
        trim($reference),
        $context,
      ));
    }

    return $definition;
  }

  /** Released projects used file stems in startingParty before actor IDs existed. */
  public function requireStartingPartyActor(string $reference): ActorDefinition
  {
    // Authored IDs always win; legacy filenames cannot shadow modern actors.
    if (($definition = $this->get($reference)) !== null) { return $definition; }
    $candidates = $this->legacyStartingPartyIds[self::normalize($reference)] ?? [];
    if (count($candidates) > 1) {
      throw new RuntimeException(sprintf('Ambiguous legacy starting-party actor reference "%s".', $reference));
    }
    if (count($candidates) === 1) {
      Debug::warn(sprintf(
        'Legacy starting-party reference "%s" uses actor filename instead of provisional id "%s". Run the confirmed project actor identity migration to update IDs and references. No project file was changed.',
        $reference, $candidates[0],
      ));
      return $this->require($candidates[0], 'loading the legacy project starting party');
    }
    return $this->require($reference, 'loading the project starting party');
  }

  public function set(string $path, mixed $value): void
  {
    if (! $value instanceof ActorDefinition) {
      throw new InvalidArgumentException('ActorStore values must be ActorDefinition instances.');
    }

    if (self::normalize($path) !== self::normalize($value->id)) {
      throw new InvalidArgumentException('ActorStore keys must match the actor id; aliases are not supported.');
    }
    $this->register($value);
  }

  public function has(string $path): bool
  {
    return $this->resolveId($path) !== null;
  }

  /** Returns the authored ID; display names and filenames are not references. */
  public function canonicalId(string $reference): ?string
  {
    $id = $this->resolveId($reference);

    return $id !== null ? $this->definitions[$id]->id : null;
  }

  public function persist(): void
  {
  }

  private function register(ActorDefinition $definition): void
  {
    $id = self::normalize($definition->id);

    if (isset($this->definitions[$id])) {
      throw new RuntimeException(sprintf('Duplicate actor definition identity: %s.', $definition->id));
    }

    $this->definitions[$id] = $definition;
  }

  private function resolveId(string $reference): ?string
  {
    $id = self::normalize($reference);
    return isset($this->definitions[$id]) ? $id : null;
  }

  private static function normalize(string $reference): string
  {
    return strtolower(trim($reference));
  }
}
