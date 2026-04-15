<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Describes how the target should be presented during summon playback.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonTargetPresentation
{
  public function __construct(
    public string $mode = 'full_screen',
    public bool $showCasterNameBanner = true,
  )
  {
    $this->mode = trim($mode) !== '' ? trim($mode) : 'full_screen';
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['mode'] ?? 'full_screen'),
      boolval($data['showCasterNameBanner'] ?? true),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'mode' => $this->mode,
      'showCasterNameBanner' => $this->showCasterNameBanner,
    ];
  }
}
