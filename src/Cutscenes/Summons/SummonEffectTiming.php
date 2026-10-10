<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Ichiloto\Engine\Util\Debug;

/**
 * Represents when the battle effect should resolve during summon playback.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonEffectTiming
{
  public const string DEFAULT_MODE = 'end';
  public const array AUTHORING_MODES = ['end', 'cue', 'frame'];

  public function __construct(
    public string $mode = self::DEFAULT_MODE,
    public ?string $cueId = null,
    public ?int $frame = null,
  )
  {
    $mode = strtolower(trim($mode));
    if ($mode === '') { $mode = self::DEFAULT_MODE; }
    if (!in_array($mode, [...self::AUTHORING_MODES, 'explicit_frame'], true)) {
      Debug::warn(sprintf('Unknown summon effect timing mode "%s"; using "%s".', $mode, self::DEFAULT_MODE));
      $mode = self::DEFAULT_MODE;
    }
    $this->mode = $mode;
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
      strval($data['mode'] ?? self::DEFAULT_MODE),
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
