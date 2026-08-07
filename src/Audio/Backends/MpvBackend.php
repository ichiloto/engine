<?php

namespace Ichiloto\Engine\Audio\Backends;

/**
 * Plays audio through mpv.
 *
 * mpv decodes virtually every common audio format, supports native looping and
 * per-process volume, which makes it the preferred backend when installed.
 *
 * @package Ichiloto\Engine\Audio\Backends
 */
class MpvBackend extends CommandLineAudioBackend
{
  /**
   * @inheritDoc
   */
  public function getExecutableName(): string
  {
    return 'mpv';
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
      '--no-video',
      '--no-terminal',
      '--really-quiet',
      sprintf('--volume=%d', (int)round($this->clampVolume($volume) * 100)),
    ];

    if ($startAtSeconds > 0) {
      $command[] = sprintf('--start=%.2F', $startAtSeconds);
    }

    if ($loop) {
      $command[] = '--loop-file=inf';
    }

    $command[] = '--';
    $command[] = $filePath;

    return $command;
  }
}
