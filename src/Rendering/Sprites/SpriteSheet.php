<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;

/** Contiguous row-major frames; unused trailing cells are never selected. */
final readonly class SpriteSheet
{
  public function __construct(
    public int $frameWidth,
    public int $frameHeight,
    public int $columns,
    public int $rows,
    public int $frames,
    public int $frameDurationMs = 80,
    public int $stepDurationMs = 160,
    public int $idleFrame = 0,
  )
  {
    if ($frameWidth < 1 || $frameHeight < 1 || $columns < 1 || $rows < 1
      || $frameWidth > 4294967295 || $frameHeight > 4294967295
      || $columns > intdiv(4294967295, $frameWidth) || $rows > intdiv(4294967295, $frameHeight)
      || $frames < 1 || intdiv($frames - 1, $columns) >= $rows
      || $idleFrame < 0 || $idleFrame >= $frames
      || $frameDurationMs < 1 || $frameDurationMs > 60000
      || $stepDurationMs < 1 || $stepDurationMs > 60000) {
      throw new InvalidArgumentException('Invalid sprite sheet grid, populated frame count, idle frame or animation duration (1-60000 ms).');
    }
  }

  public function sourceRect(int $frame): SpriteSourceRect
  {
    if ($frame < 0 || $frame >= $this->frames) {
      throw new InvalidArgumentException('Sprite frame is outside the populated sheet frames.');
    }
    return new SpriteSourceRect(($frame % $this->columns) * $this->frameWidth,
      intdiv($frame, $this->columns) * $this->frameHeight, $this->frameWidth, $this->frameHeight);
  }
}
