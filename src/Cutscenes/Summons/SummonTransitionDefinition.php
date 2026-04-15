<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Defines how a summon cutscene should transition in or out.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonTransitionDefinition
{
  public function __construct(
    public string $type = 'fadeToBlack',
    public int $durationMs = 0,
    public ?string $color = null,
    public ?string $easing = null,
    public ?string $maskAssetId = null,
  )
  {
    $this->type = trim($type) !== '' ? trim($type) : 'fadeToBlack';
    $this->durationMs = max(0, $durationMs);
    $this->color = $color !== null && trim($color) !== '' ? trim($color) : null;
    $this->easing = $easing !== null && trim($easing) !== '' ? trim($easing) : null;
    $this->maskAssetId = $maskAssetId !== null && trim($maskAssetId) !== '' ? trim($maskAssetId) : null;
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['type'] ?? 'fadeToBlack'),
      intval($data['durationMs'] ?? 0),
      isset($data['color']) ? strval($data['color']) : null,
      isset($data['easing']) ? strval($data['easing']) : null,
      isset($data['maskAssetId']) ? strval($data['maskAssetId']) : null,
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'type' => $this->type,
      'durationMs' => $this->durationMs,
      'color' => $this->color,
      'easing' => $this->easing,
      'maskAssetId' => $this->maskAssetId,
    ], static fn(mixed $value): bool => $value !== null && $value !== '');
  }
}
