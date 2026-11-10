<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Authored transit artwork. The source trails a battler travelling left. */
final readonly class FlashStepWake
{
  /** @param non-empty-list<int> $opacityPercent Equally spaced samples across either transit leg. */
  public function __construct(public string $image, public SpriteSourceRect $crop, public float $baseWidth,
    public float $maxWidth, public float $height, public float $offsetY, public float $outboundGap,
    public float $widthPerDistance, public array $opacityPercent)
  {
    SpriteValidation::validateAssetPath($image);
    if (!array_all([$baseWidth, $maxWidth, $height, $offsetY, $outboundGap, $widthPerDistance],
      static fn(float $value): bool => is_finite($value))
      || $baseWidth <= 0 || $height <= 0 || $maxWidth < $baseWidth || $outboundGap < 0
      || $widthPerDistance < 0 || $widthPerDistance > 1
      || !array_is_list($opacityPercent) || $opacityPercent === [] || count($opacityPercent) > 120
      || array_any($opacityPercent, static fn($value): bool => !is_int($value) || $value < 0 || $value > 100)) {
      throw new InvalidArgumentException('Flash-step wake needs finite body-unit dimensions, a width growth from 0 to 1 and a bounded opacity sequence.');
    }
  }

  public static function fromArray(mixed $data): self
  {
    $measurements = ['baseWidth', 'maxWidth', 'height', 'offsetY', 'outboundGap', 'widthPerDistance'];
    $cropFields = ['x', 'y', 'width', 'height'];
    $crop = is_array($data) ? ($data['crop'] ?? null) : null;
    if (!is_array($data) || array_diff(array_keys($data), ['image', 'crop', 'opacityPercent', ...$measurements]) !== []
      || !is_string($data['image'] ?? null) || !is_array($data['opacityPercent'] ?? null)
      || !is_array($crop) || count($crop) !== count($cropFields)
      || !array_all($cropFields, static fn(string $field): bool => is_int($crop[$field] ?? null))
      || !array_all($measurements, static fn(string $field): bool => is_int($data[$field] ?? null) || is_float($data[$field] ?? null))) {
      throw new InvalidArgumentException('Flash-step wake requires an image, source crop, placement measurements and opacity samples.');
    }
    return new self($data['image'], new SpriteSourceRect(...array_map(static fn(string $field): int => $crop[$field], $cropFields)),
      ...array_map(static fn(string $field): float => (float)$data[$field], $measurements), opacityPercent: $data['opacityPercent']);
  }

  public function toArray(): array
  {
    return [...get_object_vars($this), 'crop' => $this->crop->toArray()];
  }
}
