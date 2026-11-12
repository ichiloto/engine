<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use LogicException;

/** Project-authored progression rules, never player preferences or saved character state. */
final readonly class ProgressionSettings implements ConfigInterface
{
  public const string LEVEL_UP_RECOVERY = 'levelUpRecovery';

  public function __construct(public LevelUpRecovery $levelUpRecovery = LevelUpRecovery::FULL)
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
      if ($key === 'restoreVitalsOnLevelUp') {
        // The boolean this rule replaced: name its equivalent rather than guess.
        throw new InvalidArgumentException(sprintf(
          "system.progression.restoreVitalsOnLevelUp was replaced by %s: write '%s' for true or '%s' for false.",
          self::LEVEL_UP_RECOVERY, LevelUpRecovery::FULL->value, LevelUpRecovery::NONE->value,
        ));
      }
      if ($key !== self::LEVEL_UP_RECOVERY) {
        throw new InvalidArgumentException(sprintf('Unknown system.progression field: %s.', $key));
      }
      if (! is_string($value) || LevelUpRecovery::tryFrom($value) === null) {
        throw new InvalidArgumentException(sprintf(
          'system.progression.%s must be one of: %s.',
          self::LEVEL_UP_RECOVERY,
          implode(', ', array_map(static fn(LevelUpRecovery $mode): string => $mode->value, LevelUpRecovery::cases())),
        ));
      }
    }

    return new self(LevelUpRecovery::from($data[self::LEVEL_UP_RECOVERY] ?? LevelUpRecovery::FULL->value));
  }

  public function get(string $path, mixed $default = null): mixed
  {
    return $path === self::LEVEL_UP_RECOVERY ? $this->levelUpRecovery : $default;
  }

  public function has(string $path): bool
  {
    return $path === self::LEVEL_UP_RECOVERY;
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
