<?php

namespace Ichiloto\Engine\Audio\Backends;

/**
 * Plays audio through aplay (ALSA).
 *
 * aplay is the lowest common denominator on Linux: it only decodes wav (and a
 * few legacy formats), has no volume control and cannot loop. It is used as a
 * last resort when no richer player is installed.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
class AplayBackend extends CommandLineAudioBackend
{
  /**
   * @inheritDoc
   */
  public function getExecutableName(): string
  {
    return 'aplay';
  }

  /**
   * @inheritDoc
   */
  public function supportsNativeLooping(): bool
  {
    return false;
  }

  /**
   * @inheritDoc
   */
  public function getSupportedExtensions(): array
  {
    return ['wav', 'au'];
  }

  /**
   * @inheritDoc
   */
  public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
  {
    return [
      $this->getExecutableName(),
      '-q',
      $filePath,
    ];
  }
}
