<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents playback defaults for one summon cutscene.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonPlaybackConfig
{
  public function __construct(
    public float $defaultSpeed = 1.0,
    public bool $allowSkip = false,
    public bool $loopPreview = false,
  )
  {
    $this->defaultSpeed = max(0.01, $defaultSpeed);
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      isset($data['defaultSpeed']) ? floatval($data['defaultSpeed']) : 1.0,
      boolval($data['allowSkip'] ?? false),
      boolval($data['loopPreview'] ?? false),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'defaultSpeed' => $this->defaultSpeed,
      'allowSkip' => $this->allowSkip,
      'loopPreview' => $this->loopPreview,
    ];
  }
}
