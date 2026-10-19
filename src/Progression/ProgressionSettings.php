<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use LogicException;

/** Project-authored progression rules, never player preferences or saved character state. */
final readonly class ProgressionSettings implements ConfigInterface
{
  public function __construct(public bool $restoreVitalsOnLevelUp = true)
  {
  }

  public static function fromSystemData(array $data): self
  {
    return self::fromArray(array_key_exists('progression', $data) ? $data['progression'] : []);
  }

  public static function fromArray(mixed $data): self
  {
    if (! is_array($data)) {
      throw new InvalidArgumentException('system.progression must be an associative array.');
    }

    foreach ($data as $key => $value) {
      if ($key !== 'restoreVitalsOnLevelUp') {
        throw new InvalidArgumentException(sprintf('Unknown system.progression field: %s.', $key));
      }
      if (! is_bool($value)) {
        throw new InvalidArgumentException('system.progression.restoreVitalsOnLevelUp must be a boolean.');
      }
    }

    return new self($data['restoreVitalsOnLevelUp'] ?? true);
  }

  public function get(string $path, mixed $default = null): mixed
  {
    return $path === 'restoreVitalsOnLevelUp' ? $this->restoreVitalsOnLevelUp : $default;
  }

  public function has(string $path): bool
  {
    return $path === 'restoreVitalsOnLevelUp';
  }

  public function set(string $path, mixed $value): void
  {
    throw new LogicException('Progression settings are immutable project-authored system data.');
  }

  public function persist(): void
  {
    throw new LogicException('Runtime progression settings cannot rewrite project system data.');
  }
}
