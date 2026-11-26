<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

final readonly class EffectGroundSpanReveal
{
  private function __construct(
    public int $startFrame,
    public float $featherPixels,
    public float $beforeContactOpacity,
  ) {}

  public static function fromArray(mixed $source): self
  {
    if (!is_array($source) || array_diff(array_keys($source), ['startFrame', 'featherPixels', 'beforeContactOpacity']) !== []
      || !is_int($source['startFrame'] ?? null) || $source['startFrame'] < 0) {
      throw new InvalidArgumentException('groundSpan.reveal requires a nonnegative authored startFrame.');
    }
    $feather = array_key_exists('featherPixels', $source) ? $source['featherPixels'] : 0;
    $opacity = array_key_exists('beforeContactOpacity', $source) ? $source['beforeContactOpacity'] : 1;
    if (!self::isWithin($feather, 256) || !self::isWithin($opacity, 1)) {
      throw new InvalidArgumentException('groundSpan.reveal requires featherPixels within 0–256 and beforeContactOpacity within 0–1.');
    }
    return new self($source['startFrame'], (float)$feather, (float)$opacity);
  }

  private static function isWithin(mixed $value, float $limit): bool
  {
    return (is_int($value) || is_float($value)) && is_finite($value) && $value >= 0 && $value <= $limit;
  }

  public function toArray(): array
  {
    return ['startFrame' => $this->startFrame, 'featherPixels' => $this->featherPixels,
      'beforeContactOpacity' => $this->beforeContactOpacity];
  }
}
