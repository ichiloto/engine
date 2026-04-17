<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Plays compiled summon cutscenes frame-by-frame.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutscenePlayer
{
  /**
   * @param callable(int, array<int, array<string, mixed>>): void $renderFrame
   * @param callable(array<string, mixed>, int): void|null $onCue
   * @return void
   */
  public function play(
    SummonCompiledCutscene $cutscene,
    callable $renderFrame,
    ?callable $onCue = null,
  ): void
  {
    $lengthFrames = max(1, intval($cutscene->defaults['lengthFrames'] ?? 1));
    $playback = is_array($cutscene->defaults['playback'] ?? null)
      ? $cutscene->defaults['playback']
      : [];
    $speed = max(0.01, floatval($playback['defaultSpeed'] ?? 1.0));
    $frameDelayMicroseconds = intval(round((1000000 / max(1, $cutscene->fps)) / $speed));
    $cuesByFrame = [];

    foreach ($cutscene->cueSchedule as $cue) {
      $frame = intval($cue['frame'] ?? -1);

      if ($frame < 0) {
        continue;
      }

      $cuesByFrame[$frame] ??= [];
      $cuesByFrame[$frame][] = $cue;
    }

    for ($frameIndex = 0; $frameIndex < $lengthFrames; $frameIndex++) {
      $segments = $this->resolveSegmentsForFrame($cutscene, $frameIndex);
      $renderFrame($frameIndex, $segments);

      if ($onCue !== null) {
        foreach ($cuesByFrame[$frameIndex] ?? [] as $cue) {
          $onCue($cue, $frameIndex);
        }
      }

      usleep(max(0, $frameDelayMicroseconds));
    }
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  protected function resolveSegmentsForFrame(SummonCompiledCutscene $cutscene, int $frameIndex): array
  {
    return array_values(array_filter(
      $cutscene->playbackSegments,
      static fn(array $segment): bool =>
        $frameIndex >= intval($segment['startFrame'] ?? -1) &&
        $frameIndex <= intval($segment['endFrame'] ?? -1)
    ));
  }
}