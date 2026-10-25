<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use LogicException;

/**
 * The project's dash rule, authored in system data (`system.dash`): whether
 * the player may dash at all, and how many times faster than walking a
 * dashing step goes. A project that leaves `dash` out has no dash.
 *
 * ```php
 * 'dash' => ['enabled' => true, 'speedMultiplier' => 2.0],
 * ```
 *
 * A map turns dashing off for itself with `'disableDashing' => true` in its
 * data, as RPG Maker's Disable Dashing does ({@see self::readMapDisablesDashing()}).
 * Project-authored, never a player preference or saved state: the player's
 * Always Dash option lives in player settings ({@see PlayerDash}).
 */
final readonly class DashSettings implements ConfigInterface
{
  public const string KEY = 'dash';
  public const string ENABLED = 'enabled';
  public const string SPEED_MULTIPLIER = 'speedMultiplier';
  /** RPG Maker's dash: one move speed up, twice the walking speed. */
  public const float DEFAULT_SPEED_MULTIPLIER = 2.0;
  /**
   * The fastest dash: a step a quarter of the walking time, 4 frames at 60
   * updates per second. Held walking decides each step on the update before
   * the one in progress ends, so a step needs a few updates of its own.
   */
  public const float MAX_SPEED_MULTIPLIER = 4.0;
  /** The map data key that turns dashing off on that map. */
  public const string MAP_DISABLE_DASHING = 'disableDashing';

  public function __construct(public bool $enabled = false, public float $speedMultiplier = self::DEFAULT_SPEED_MULTIPLIER)
  {
    if (! is_finite($speedMultiplier) || $speedMultiplier <= 1.0 || $speedMultiplier > self::MAX_SPEED_MULTIPLIER) {
      throw new InvalidArgumentException(sprintf('system.dash.%s must be a number greater than 1 and at most %g.',
        self::SPEED_MULTIPLIER, self::MAX_SPEED_MULTIPLIER));
    }
  }

  /** The rule a project's `Data/system.php` authors; no `dash` section means no dash. */
  public static function fromSystemData(mixed $data): self
  {
    return is_array($data) && array_key_exists(self::KEY, $data) ? self::fromArray($data[self::KEY]) : new self();
  }

  public static function fromArray(mixed $data): self
  {
    if (! is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new InvalidArgumentException('system.dash must be an associative array.');
    }
    foreach ($data as $key => $value) {
      if ($key !== self::ENABLED && $key !== self::SPEED_MULTIPLIER) {
        throw new InvalidArgumentException(sprintf('Unknown system.dash field: %s.', $key));
      }
    }
    $enabled = $data[self::ENABLED] ?? false;
    if (! is_bool($enabled)) {
      throw new InvalidArgumentException(sprintf('system.dash.%s must be true or false.', self::ENABLED));
    }
    $multiplier = $data[self::SPEED_MULTIPLIER] ?? self::DEFAULT_SPEED_MULTIPLIER;
    if (! is_int($multiplier) && ! is_float($multiplier)) {
      throw new InvalidArgumentException(sprintf('system.dash.%s must be a number.', self::SPEED_MULTIPLIER));
    }

    return new self($enabled, (float) $multiplier);
  }

  /**
   * Whether a map's data turns dashing off there. Absent, the map follows
   * the project's rule.
   *
   * @param array<string, mixed> $map The map's data.
   */
  public static function readMapDisablesDashing(array $map): bool
  {
    $disabled = $map[self::MAP_DISABLE_DASHING] ?? false;
    if (! is_bool($disabled)) {
      throw new InvalidArgumentException(sprintf('A map\'s %s must be true or false.', self::MAP_DISABLE_DASHING));
    }

    return $disabled;
  }

  public function get(string $path, mixed $default = null): mixed
  {
    return match ($path) {
      self::ENABLED => $this->enabled,
      self::SPEED_MULTIPLIER => $this->speedMultiplier,
      default => $default,
    };
  }

  public function has(string $path): bool
  {
    return $path === self::ENABLED || $path === self::SPEED_MULTIPLIER;
  }

  public function set(string $path, mixed $value): void
  {
    throw new LogicException('Dash settings are immutable project-authored system data.');
  }

  public function persist(): void
  {
    throw new LogicException('Runtime dash settings cannot rewrite project system data.');
  }
}
