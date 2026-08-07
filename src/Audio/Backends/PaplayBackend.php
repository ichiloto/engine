<?php

namespace Ichiloto\Engine\Audio\Backends;

/**
 * Plays audio through paplay (PulseAudio / PipeWire).
 *
 * paplay is present on most desktop Linux systems and works under WSLg, which
 * forwards PulseAudio to the Windows host. It cannot decode mp3 and cannot
 * loop by itself, so the AudioManager emulates background music looping by
 * respawning the player when the track ends.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
class PaplayBackend extends CommandLineAudioBackend
{
  /**
   * paplay expresses volume as an integer where 65536 is 100%.
   */
  protected const int FULL_VOLUME = 65536;

  /**
   * @inheritDoc
   */
  public function getExecutableName(): string
  {
    return 'paplay';
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
    return ['wav', 'ogg', 'oga', 'opus', 'flac', 'aiff', 'aif'];
  }

  /**
   * @inheritDoc
   */
  public function buildCommand(string $filePath, float $volume, bool $loop): array
  {
    return [
      $this->getExecutableName(),
      sprintf('--volume=%d', (int)round($this->clampVolume($volume) * self::FULL_VOLUME)),
      $filePath,
    ];
  }
}
