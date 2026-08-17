<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * The deterministic result of advancing a summon playback session.
 *
 * Inspection APIs never create one of these results. Only elapsed-time
 * traversal reports crossed frames and cues, which prevents an Editor seek or
 * scrub from pretending that runtime effects fired.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final readonly class SummonPlaybackUpdate
{
  /**
   * @param int[] $crossedFrames Frames entered during this traversal.
   * @param array<int, array<string, mixed>> $crossedCues Cues encountered in
   *   deterministic frame and source order.
   */
  public function __construct(
    public array $crossedFrames = [],
    public array $crossedCues = [],
  )
  {
  }
}
