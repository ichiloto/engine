<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/** Normalized point of the drawn image placed at the sprite's ground anchor. */
final readonly class PresentationSpritePivot
{
  public function __construct(public float $x = .5, public float $y = 1.0)
  {
    if (!is_finite($x) || !is_finite($y) || min($x, $y) < 0 || max($x, $y) > 1) {
      throw new InvalidArgumentException('Image pivot coordinates must be finite numbers in 0..1.');
    }
  }

  public static function fromArray(array $data): self
  {
    if (array_diff(array_keys($data), ['x', 'y']) !== []
      || (!is_int($data['x'] ?? null) && !is_float($data['x'] ?? null))
      || (!is_int($data['y'] ?? null) && !is_float($data['y'] ?? null))) {
      throw new InvalidArgumentException('Image pivot requires normalized numeric x/y coordinates.');
    }
    return new self($data['x'], $data['y']);
  }

  /** @return array{x: float, y: float} */
  public function toArray(): array { return ['x' => $this->x, 'y' => $this->y]; }
}
