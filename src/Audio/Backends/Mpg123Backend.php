<?php

namespace Ichiloto\Engine\Audio\Backends;

/**
 * Plays mp3 files through mpg123.
 *
 * mpg123 complements paplay on minimal Linux systems: paplay covers wav/ogg
 * while mpg123 covers mp3. It supports native looping and a scale-factor
 * based volume control.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
class Mpg123Backend extends CommandLineAudioBackend
{
  /**
   * mpg123 expresses volume as a scale factor where 32768 is 100%.
   */
  protected const int FULL_SCALE = 32768;

  /**
   * @inheritDoc
   */
  public function getExecutableName(): string
  {
    return 'mpg123';
  }

  /**
   * @inheritDoc
   */
  public function supportsNativeLooping(): bool
  {
    return true;
  }

  /**
   * @inheritDoc
   */
  public function getSupportedExtensions(): array
  {
    return ['mp3'];
  }

  /**
   * @inheritDoc
   */
  public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
  {
    $command = [
      $this->getExecutableName(),
      '-q',
      '-f',
      strval((int)round($this->clampVolume($volume) * self::FULL_SCALE)),
    ];

    if ($loop) {
      // "--loop -1" makes mpg123 repeat the track indefinitely.
      $command[] = '--loop';
      $command[] = '-1';
    }

    $command[] = $filePath;

    return $command;
  }
}
