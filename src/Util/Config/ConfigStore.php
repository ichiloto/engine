<?php

namespace Ichiloto\Engine\Util\Config;

use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;

/**
 * The config store.
 *
 * @package Ichiloto\Engine\Util
 */
final class ConfigStore
{
  /**
   * The config store.
   *
   * @var array<class-string, ConfigInterface>
   */
  protected static array $store = [];

  /**
   * Gets the config instance.
   *
   * @param class-string $configClass The config class.
   * @return ConfigInterface The config instance.
   */
  public static function get(string $configClass): ConfigInterface
  {
    return self::$store[$configClass] ?? throw new InvalidArgumentException("Config class not found: $configClass");
  }

  /**
   * Puts the given config instance in the store.
   *
   * @param class-string $configClass The config class.
   * @param ConfigInterface $config The config instance.
   * @return void
   */
  public static function put(string $configClass, ConfigInterface $config): void
  {
    self::$store[$configClass] = $config;
  }

  /**
   * Removes the given config class from the store.
   *
   * @param class-string $configClass The config class.
   * @return void
   */
  public static function remove(string $configClass): void
  {
    unset(self::$store[$configClass]);
  }

  /**
   * Expose a host's configuration to legacy readers for one operation. Null masks an entry.
   *
   * @template TResult
   * @param array<class-string, ConfigInterface|null> $overrides
   * @param callable(): TResult $operation
   * @return TResult
   */
  public static function withOverrides(array $overrides, callable $operation): mixed
  {
    $previous = array_intersect_key(self::$store, $overrides);
    try {
      foreach ($overrides as $class => $config) {
        if ($config === null) { self::remove($class); }
        else { self::put($class, $config); }
      }
      return $operation();
    } finally {
      self::$store = $previous + array_diff_key(self::$store, $overrides);
    }
  }

  /**
   * Checks if the config store has the given config class.
   *
   * @param class-string $configClass The config class.
   * @return bool True if the config store has the given config class, false otherwise.
   */
  public static function has(string $configClass): bool
  {
    return isset(self::$store[$configClass]);
  }

  /**
   * Checks if the config store does not have the given config class.
   *
   * @param class-string $configClass The config class.
   * @return bool True if the config store does not have the given config class, false otherwise.
   */
  public static function doesntHave(string $configClass): bool
  {
    return !self::has($configClass);
  }
}
