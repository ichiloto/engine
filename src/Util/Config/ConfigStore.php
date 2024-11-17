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
    if (self::doesntHave($configClass)) {
      throw new InvalidArgumentException("Config class not found: $configClass");
    }

    return self::$store[$configClass];
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