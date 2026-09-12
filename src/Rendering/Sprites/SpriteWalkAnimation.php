<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use InvalidArgumentException;

/** Presentation-only step envelope. Never infers held keys or moves the Player. */
final class SpriteWalkAnimation
{
  private ?GraphicalSpriteDefinition $direction = null;
  private float $elapsed = 0.0;
  private float $remaining = 0.0;

  public function step(GraphicalSpriteDefinition $direction): void
  {
    if ($direction->sheet === null) { $this->stop(); return; }
    if ($this->direction !== $direction || $this->remaining <= 0.0) {
      $this->elapsed = 0.0;
    }
    $this->direction = $direction;
    $this->remaining = $direction->sheet->stepDurationMs / 1000;
  }

  public function advance(float $seconds): void
  {
    if (!is_finite($seconds) || $seconds < 0.0) {
      throw new InvalidArgumentException('Sprite animation delta must be finite and nonnegative.');
    }
    if ($seconds >= $this->remaining) {
      $this->stop();
    } elseif ($this->direction?->sheet !== null) {
      $this->remaining -= $seconds;
      $cycle = $this->direction->sheet->frames * ($this->direction->sheet->frameDurationMs / 1000);
      $this->elapsed = fmod($this->elapsed + $seconds, $cycle);
    }
  }

  public function stop(): void
  {
    $this->direction = null;
    $this->elapsed = $this->remaining = 0.0;
  }

  public function present(GraphicalSpriteDefinition $direction): GraphicalSpriteDefinition
  {
    $sheet = $direction->sheet;
    if ($sheet === null || $this->direction !== $direction || $this->remaining <= 0.0) {
      return $direction;
    }
    $frame = (int) floor(($this->elapsed * 1000 + 0.000001) / $sheet->frameDurationMs) % $sheet->frames;
    return $direction->atFrame($frame);
  }
}
