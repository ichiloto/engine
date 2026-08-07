<?php

namespace Ichiloto\Engine\Audio\Backends;

/**
 * Plays audio through afplay, which ships with every macOS installation.
 *
 * afplay cannot loop by itself, so the AudioManager emulates background music
 * looping by respawning the player when the track ends.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
class AfplayBackend extends CommandLineAudioBackend
{
  /**
   * @inheritDoc
   */
  public function getExecutableName(): string
  {
    return 'afplay';
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
    return ['wav', 'mp3', 'm4a', 'aac', 'aiff', 'aif', 'caf'];
  }

  /**
   * @inheritDoc
   */
  public function buildCommand(string $filePath, float $volume, bool $loop): array
  {
    return [
      $this->getExecutableName(),
      '-v',
      sprintf('%.2F', $this->clampVolume($volume)),
      $filePath,
    ];
  }
}
