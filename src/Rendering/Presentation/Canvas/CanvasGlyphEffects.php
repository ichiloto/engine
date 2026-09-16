<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Effects expand paint bounds, never the authoritative local text grid. */
final readonly class CanvasGlyphEffects
{
  public function __construct(
    public float $outlineWidth,
    public PresentationColor $outlineColor,
    public float $shadowOffsetX,
    public float $shadowOffsetY,
    public float $shadowSigma,
    public float $shadowOpacity,
    public PresentationColor $shadowColor,
  ) {
    foreach ([$outlineWidth, $shadowOffsetX, $shadowOffsetY, $shadowSigma, $shadowOpacity] as $value) {
      if (!is_finite($value)) { throw new InvalidArgumentException('Glyph effects must be finite.'); }
    }
    if ($outlineWidth < 0 || $outlineWidth > 4 || abs($shadowOffsetX) > 8 || abs($shadowOffsetY) > 8
      || $shadowSigma < 0 || $shadowSigma > 4 || $shadowOpacity < 0 || $shadowOpacity > 1) {
      throw new InvalidArgumentException('Glyph effects exceed the bounded contour or shadow limits.');
    }
  }

  /** @return array{left: int, top: int, right: int, bottom: int} */
  public function padding(): array
  {
    $radius = $this->outlineWidth + ceil(3 * $this->shadowSigma);
    return ['left' => (int)ceil($radius + max(0, -$this->shadowOffsetX)),
      'top' => (int)ceil($radius + max(0, -$this->shadowOffsetY)),
      'right' => (int)ceil($radius + max(0, $this->shadowOffsetX)),
      'bottom' => (int)ceil($radius + max(0, $this->shadowOffsetY))];
  }

  public function toArray(): array
  {
    return ['outline' => ['width' => $this->outlineWidth, 'color' => $this->outlineColor->toArray()],
      'shadow' => ['offsetX' => $this->shadowOffsetX, 'offsetY' => $this->shadowOffsetY,
        'sigma' => $this->shadowSigma, 'opacity' => $this->shadowOpacity, 'color' => $this->shadowColor->toArray()]];
  }
}
