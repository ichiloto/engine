<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;

/** Authored pivot destination and contain limits, never terminal coordinates. */
final readonly class BattlerSlot
{
  public function __construct(
    public float $x,
    public float $y,
    public float $width,
    public float $height,
  ) {
    foreach ([$x, $y, $width, $height] as $value) {
      if (!is_finite($value) || $value < 0 || $value > 16384) {
        throw new InvalidArgumentException('Battler slot geometry must be finite and within 0..16384.');
      }
    }
    if ($width <= 0 || $height <= 0) {
      throw new InvalidArgumentException('Battler slot contain limits must be positive.');
    }
  }

  public function place(BattlerArtwork $art): CanvasRectangle
  {
    $scale = min($this->width / $art->width, $this->height / $art->height);
    return new CanvasRectangle($this->x - $art->pivotX * $scale, $this->y - $art->pivotY * $scale,
      $art->width * $scale, $art->height * $scale);
  }
}
