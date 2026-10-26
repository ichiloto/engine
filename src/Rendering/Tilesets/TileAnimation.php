<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * RPG Maker's tile animation counter: it advances every 30 frames at 60 frames
 * per second, and each animated tile shows frames[counter % its frame count].
 */
final class TileAnimation
{
  public const float FRAME_SECONDS = 30 / 60;
  /** Water cycles 4 frames and waterfalls 3; a 12-step counter serves both. */
  public const int CYCLE = 12;

  public static function getFrame(float $seconds): int
  {
    return (int)floor(max(0.0, $seconds) / self::FRAME_SECONDS) % self::CYCLE;
  }
}
