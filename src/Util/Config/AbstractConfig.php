<?php

namespace Ichiloto\Engine\Util\Config;

use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

/**
 * The abstract configuration class.
 *
 * @package Ichiloto\Engine\Util
 */
abstract class AbstractConfig implements ConfigInterface
{
  /**
   * AbstractConfig constructor.
   *
   * @param array $options The options to use.
   */
  public function __construct(protected array $options = [])
  {
    $this->config = $this->load();
  }

  /**
   * The configuration array.
   *
   * @var array
   */
  protected array $config = [];

  /**
   * @inheritDoc
   */
  public function get(string $path, mixed $default = null): mixed
  {
    $config = $this->config;
    $keys = explode('.', $path);

    foreach ($keys as $path) {
      if (isset($config[$path])) {
        $config = $config[$path];
      } else {
        return $default;
      }
    }

    return $config;
  }

  /**
   * @inheritDoc
   */
  public function set(string $path, mixed $value): void
  {
    $config = &$this->config;
    $keys = explode('.', $path);

    foreach ($keys as $path) {
      if (!isset($config[$path])) {
        $config[$path] = [];
      }

      $config = &$config[$path];
    }

    $config = $value;
  }

  /**
   * @inheritDoc
   */
  public function has(string $path): bool
  {
    $config = $this->config;
    $keys = explode('.', $path);

    foreach ($keys as $path) {
      if (isset($config[$path])) {
        $config = $config[$path];
      } else {
        return false;
      }
    }

    return true;
  }

  /**
   * Loads the configuration.
   *
   * @return array The configuration array.
   */
  protected abstract function load(): array;
}