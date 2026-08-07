<?php

namespace Ichiloto\Engine\Audio\Backends;

/**
 * Plays audio through ffplay (part of FFmpeg).
 *
 * Like mpv, ffplay decodes virtually every common audio format and supports
 * native looping and per-process volume.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
class FfplayBackend extends CommandLineAudioBackend
{
  /**
   * @inheritDoc
   */
  public function getExecutableName(): string
  {
    return 'ffplay';
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
    return ['wav', 'mp3', 'ogg', 'oga', 'opus', 'flac', 'm4a', 'aac', 'aiff', 'aif'];
  }

  /**
   * @inheritDoc
   */
  public function supportsSeeking(): bool
  {
    return true;
  }

  /**
   * @inheritDoc
   */
  public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
  {
    $command = [
      $this->getExecutableName(),
      '-nodisp',
      '-autoexit',
      '-loglevel',
      'quiet',
      '-volume',
      strval((int)round($this->clampVolume($volume) * 100)),
    ];

    if ($startAtSeconds > 0) {
      $command[] = '-ss';
      $command[] = sprintf('%.2F', $startAtSeconds);
    }

    if ($loop) {
      // "-loop 0" makes ffplay repeat the input indefinitely.
      $command[] = '-loop';
      $command[] = '0';
    }

    $command[] = $filePath;

    return $command;
  }
}
