<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\{PresentationSpritePivot, PresentationViewport};
use InvalidArgumentException;

/** Uniform child-space scale about an explicit normalized origin, before parent translation. */
final readonly class CanvasGroupScale
{
  public const float MAX_FACTOR = PresentationViewport::MAX_SCALE;

  public function __construct(public float $factor, public PresentationSpritePivot $origin)
  {
    if (!is_finite($factor) || $factor <= 0 || $factor > self::MAX_FACTOR) {
      throw new InvalidArgumentException('Canvas group scale must be finite, positive and at most 8.');
    }
  }

  public static function fromArray(array $data): self
  {
    CanvasCompositeValues::validateKeys($data, ['factor', 'origin']);
    if (!is_array($data['origin'])) { throw new InvalidArgumentException('Canvas group scale requires a normalized origin.'); }
    return new self(CanvasCompositeValues::getNumber($data['factor'], 0, self::MAX_FACTOR),
      PresentationSpritePivot::fromArray($data['origin']));
  }

  public function toArray(): array { return ['factor' => $this->factor, 'origin' => $this->origin->toArray()]; }
}
