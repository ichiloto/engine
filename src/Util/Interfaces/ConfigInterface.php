<?php

namespace Ichiloto\Engine\Util\Interfaces;

/**
 * The configuration interface.
 *
 * @package Ichiloto\Engine\Util\Interfaces
 */
interface ConfigInterface
{
  /**
   * Gets the value of the given key.
   *
   * @param string $path The path to the key to get the value for.
   * @param mixed $default The default value to return if the key does not exist.
   * @return mixed The value of the given key.
   */
  public function get(string $path, mixed $default = null): mixed;

  /**
   * Sets the value of the given key.
   *
   * @param string $path The path to the key to set the value for.
   * @param mixed $value The value to set.
   * @return void
   */
  public function set(string $path, mixed $value): void;

  /**
   * Checks if the given path is set.
   *
   * @param string $path The path to the key to check.
   * @return bool True if the key is set, false otherwise.
   */
  public function has(string $path): bool;

  /**
   * Persists the configuration.
   *
   * @return void
   */
  public function persist(): void;
}