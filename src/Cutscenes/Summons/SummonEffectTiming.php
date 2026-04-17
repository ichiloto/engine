<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents when the battle effect should resolve during summon playback.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonEffectTiming
{
  public function __construct(
    public string $mode = 'cue',
    public ?string $cueId = null,
    public ?int $frame = null,
  )
  {
    $this->mode = trim($mode) !== '' ? trim($mode) : 'cue';
    $this->cueId = $cueId !== null && trim($cueId) !== '' ? trim($cueId) : null;
    $this->frame = $frame !== null ? max(0, $frame) : null;
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['mode'] ?? 'cue'),
      isset($data['cueId']) ? strval($data['cueId']) : null,
      isset($data['frame']) ? intval($data['frame']) : null,
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'mode' => $this->mode,
      'cueId' => $this->cueId,
      'frame' => $this->frame,
    ], static fn(mixed $value): bool => $value !== null && $value !== '');
  }
}
