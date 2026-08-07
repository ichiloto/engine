<?php

namespace Ichiloto\Engine\Audio\Backends;

use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;

/**
 * Base class for backends that wrap a command line audio player.
 *
 * Provides PATH-based executable detection (cached per executable) and
 * extension-based format support checks.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
abstract class CommandLineAudioBackend implements AudioBackendInterface
{
  /**
   * Cache of executable lookups, shared by all backends.
   *
   * @var array<string, bool>
   */
  protected static array $availabilityCache = [];

  /**
   * Returns the lowercase file extensions the player can decode.
   *
   * @return string[] The supported file extensions, without leading dots.
   */
  abstract public function getSupportedExtensions(): array;

  /**
   * @inheritDoc
   */
  public function isAvailable(): bool
  {
    $executable = $this->getExecutableName();

    if (! array_key_exists($executable, self::$availabilityCache)) {
      self::$availabilityCache[$executable] = $this->executableExists($executable);
    }

    return self::$availabilityCache[$executable];
  }

  /**
   * @inheritDoc
   */
  public function supports(string $filePath): bool
  {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    return in_array($extension, $this->getSupportedExtensions(), true);
  }

  /**
   * Determines whether the given executable exists on the PATH.
   *
   * @param string $executable The executable name.
   * @return bool True when the executable was found.
   */
  protected function executableExists(string $executable): bool
  {
    if (PHP_OS_FAMILY === 'Windows') {
      // Native Windows is not a supported audio target (WSL presents as Linux).
      return false;
    }

    $output = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($executable)));

    return is_string($output) && trim($output) !== '';
  }

  /**
   * Clamps the given volume to the normalized 0.0 to 1.0 range.
   *
   * @param float $volume The requested volume.
   * @return float The clamped volume.
   */
  protected function clampVolume(float $volume): float
  {
    return clamp($volume, 0.0, 1.0);
  }
}
