<?php

namespace Ichiloto\Engine\Util\Stores;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use RuntimeException;

/** Project actor catalogue and authoritative character factory. */
final class ActorStore implements ConfigInterface
{
  /** @var array<string, ActorDefinition> */
  private array $definitions = [];
  /** @var array<string, string> */
  private array $references = [];

  public function __construct(?string $directory = null)
  {
    $directory ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'Actors');

    foreach (glob(Path::join($directory, '*.php')) ?: [] as $filename) {
      $payload = require $filename;

      if (! is_array($payload)) {
        throw new RuntimeException(sprintf('Actor definition %s must return an array.', $filename));
      }

      $definition = ActorDefinition::fromArray($payload, $filename);
      $this->register($definition, pathinfo($filename, PATHINFO_FILENAME));
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

  public function set(string $path, mixed $value): void
  {
    if (! $value instanceof ActorDefinition) {
      throw new InvalidArgumentException('ActorStore values must be ActorDefinition instances.');
    }

    $this->register($value, $path);
  }

  public function has(string $path): bool
  {
    return $this->resolveId($path) !== null;
  }

  public function persist(): void
  {
  }

  private function register(ActorDefinition $definition, string $fileReference): void
  {
    $id = self::normalize($definition->id);

    if (isset($this->definitions[$id])) {
      throw new RuntimeException(sprintf('Duplicate actor definition identity: %s.', $definition->id));
    }

    $this->definitions[$id] = $definition;
    $this->registerReference($definition->id, $id);
    $this->registerReference(strval($definition->data()['name'] ?? ''), $id);
    $this->registerReference($fileReference, $id);
  }

  private function registerReference(string $reference, string $id): void
  {
    $reference = self::normalize($reference);

    if ($reference === '') {
      return;
    }

    $existing = $this->references[$reference] ?? null;

    if ($existing !== null && $existing !== $id) {
      throw new RuntimeException(sprintf('Actor reference "%s" is ambiguous.', $reference));
    }

    $this->references[$reference] = $id;
  }

  private function resolveId(string $reference): ?string
  {
    return $this->references[self::normalize($reference)] ?? null;
  }

  private static function normalize(string $reference): string
  {
    return strtolower(trim($reference));
  }
}
