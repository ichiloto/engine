<?php

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use InvalidArgumentException;

/** Authored background-music policy for a cinematic command. */
final readonly class CinematicMusicRequest
{
  public function __construct(
    public string $track,
    public bool $loop = true,
    public float $fadeIn = 0.0,
    public float $fadeOut = 0.0,
    public string $completionBehavior = 'continue',
  )
  {
    if (! in_array($completionBehavior, CinematicCommandSchema::MUSIC_COMPLETION_BEHAVIORS, true)) {
      throw new InvalidArgumentException(sprintf('Unsupported cinematic music completion behavior "%s".', $completionBehavior));
    }
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    $track = trim(strval($data['track'] ?? $data['music'] ?? ''));

    if ($track === '') {
      throw new InvalidArgumentException('cinematic_music requires a track.');
    }

    $behavior = boolval($data['restorePreviousMusic'] ?? false)
      ? 'restore_previous'
      : strtolower(trim(strval($data['completionBehavior'] ?? 'continue')));

    return new self(
      $track,
      boolval($data['loop'] ?? true),
      max(0.0, floatval($data['fadeIn'] ?? 0.0)),
      max(0.0, floatval($data['fadeOut'] ?? 0.0)),
      $behavior,
    );
  }
}
