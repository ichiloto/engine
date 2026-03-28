<?php

namespace Ichiloto\Engine\Animations;

/**
 * Represents an optional sound or flash cue tied to one animation frame.
 *
 * @package Ichiloto\Engine\Animations
 */
final class AnimationCue
{
  /**
   * @param string $soundEffect The optional sound-effect cue.
   * @param string|null $flashColor The optional flash color.
   * @param int $flashDurationFrames The flash duration in frames.
   */
  public function __construct(
    public string $soundEffect = '',
    public ?string $flashColor = null,
    public int $flashDurationFrames = 0,
  )
  {
    $this->soundEffect = trim($soundEffect);
    $this->flashColor = $flashColor !== null && $flashColor !== ''
      ? strtolower($flashColor)
      : null;
    $this->flashDurationFrames = max(0, $flashDurationFrames);
  }

  /**
   * Hydrates a cue from an array payload.
   *
   * @param array<string, mixed> $data The serialized cue data.
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['soundEffect'] ?? $data['sound'] ?? ''),
      isset($data['flashColor']) ? strval($data['flashColor']) : null,
      intval($data['flashDurationFrames'] ?? $data['flashDuration'] ?? 0),
    );
  }

  /**
   * Returns whether the cue contains any meaningful data.
   *
   * @return bool
   */
  public function isEmpty(): bool
  {
    return $this->soundEffect === ''
      && ($this->flashColor === null || $this->flashColor === '')
      && $this->flashDurationFrames <= 0;
  }

  /**
   * Returns the serialized cue payload.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'soundEffect' => $this->soundEffect,
      'flashColor' => $this->flashColor,
      'flashDurationFrames' => $this->flashDurationFrames,
    ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0);
  }
}
