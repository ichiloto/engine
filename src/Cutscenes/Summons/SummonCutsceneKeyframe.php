<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents one authored keyframe on a summon cutscene track.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneKeyframe
{
  /**
   * @param array<string, int>|null $position
   * @param array<string, mixed> $payload
   */
  public function __construct(
    public int $frame,
    public int $duration = 1,
    public ?array $position = null,
    public ?string $content = null,
    public ?string $assetId = null,
    public ?string $color = null,
    public bool $visible = true,
    public int $zIndex = 0,
    public ?string $blendMode = null,
    public ?string $easing = null,
    public array $payload = [],
  )
  {
    $this->frame = max(0, $frame);
    $this->duration = max(1, $duration);
    $this->position = self::normalizePosition($position);
    $this->content = $content !== null && trim($content) !== '' ? $content : null;
    $this->assetId = $assetId !== null && trim($assetId) !== '' ? trim($assetId) : null;
    $this->color = $color !== null && trim($color) !== '' ? trim($color) : null;
    $this->blendMode = $blendMode !== null && trim($blendMode) !== '' ? trim($blendMode) : null;
    $this->easing = $easing !== null && trim($easing) !== '' ? trim($easing) : null;
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      intval($data['frame'] ?? 0),
      intval($data['duration'] ?? 1),
      self::positionFromArray($data['position'] ?? null),
      isset($data['content']) ? strval($data['content']) : null,
      isset($data['assetId']) ? strval($data['assetId']) : null,
      isset($data['color']) ? strval($data['color']) : null,
      boolval($data['visible'] ?? true),
      intval($data['zIndex'] ?? 0),
      isset($data['blendMode']) ? strval($data['blendMode']) : null,
      isset($data['easing']) ? strval($data['easing']) : null,
      is_array($data['payload'] ?? null) ? $data['payload'] : [],
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'frame' => $this->frame,
      'duration' => $this->duration,
      'position' => $this->position,
      'content' => $this->content,
      'assetId' => $this->assetId,
      'color' => $this->color,
      'visible' => $this->visible,
      'zIndex' => $this->zIndex,
      'blendMode' => $this->blendMode,
      'easing' => $this->easing,
      'payload' => $this->payload,
    ], static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== '');
  }

  /**
   * @param array<int, int>|array<string, int>|null $position
   * @return array{x: int, y: int}|null
   */
  protected static function normalizePosition(?array $position): ?array
  {
    if ($position === null) {
      return null;
    }

    if (array_is_list($position)) {
      return [
        'x' => intval($position[0] ?? 0),
        'y' => intval($position[1] ?? 0),
      ];
    }

    return [
      'x' => intval($position['x'] ?? 0),
      'y' => intval($position['y'] ?? 0),
    ];
  }

  /**
   * @param mixed $value
   * @return array<string, int>|null
   */
  protected static function positionFromArray(mixed $value): ?array
  {
    return is_array($value)
      ? self::normalizePosition($value)
      : null;
  }
}
