<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Animations\Animation;

/** Compatibility import; legacy records use the same playhead as new effects. */
final class LegacyAnimationTimeline
{
  /** @param list<string> $frames */
  public static function compileTextFrames(string $id, array $frames, int $fps): CompiledEffectTimeline
  {
    if (!array_is_list($frames) || $frames === [] || array_any($frames, static fn(mixed $frame): bool => !is_string($frame))) {
      throw new \InvalidArgumentException('Legacy text animation requires an ordered, non-empty list of text frames.');
    }
    // Text-only imports have no external resources; use the shared schema validator.
    return new EffectTimelineLibrary('')->compile($id, [
      'fps' => $fps, 'lengthFrames' => count($frames), 'restFrame' => count($frames) - 1,
      'tracks' => [['id' => 'frames', 'type' => 'glyph', 'anchor' => 'screen',
        'keyframes' => array_map(static fn(int $index, string $content): array =>
          ['frame' => $index, 'content' => $content], array_keys($frames), $frames)]],
    ], forBattle: true);
  }

  public static function compile(Animation $animation, int $fps = 10, bool $includeFlash = true,
    ?float $secondsPerFrame = null): CompiledEffectTimeline
  {
    $segments = $cues = [];
    foreach ($animation->getFrames() as $frame) {
      foreach ($frame->getCells() as $cell) {
        $segments[] = ['startFrame' => $frame->index - 1, 'endFrame' => $frame->index - 1,
          'layer' => 'glyph', 'drawCommands' => [[
            'trackId' => 'cell-' . $cell->x . '-' . $cell->y, 'position' => ['x' => $cell->x, 'y' => $cell->y],
            'content' => $cell->symbol, 'color' => $cell->color, 'zIndex' => 0,
            'payload' => ['anchor' => 'target', 'legacyPosition' => $animation->position->value],
          ]]];
      }
      $cue = $animation->getCue($frame->index);
      if ($cue?->soundEffect !== null && $cue->soundEffect !== '') {
        $cues[] = ['id' => 'sound-' . $frame->index, 'frame' => $frame->index - 1,
          'type' => 'playSound', 'payload' => ['sound' => $cue->soundEffect]];
      }
      if ($includeFlash && $cue?->flashColor !== null && $cue->flashDurationFrames > 0) {
        $segments[] = ['startFrame' => $frame->index - 1,
          'endFrame' => $frame->index + $cue->flashDurationFrames - 2,
          'layer' => 'flash', 'drawCommands' => [[
            'trackId' => 'flash-' . $frame->index, 'color' => $cue->flashColor,
            'payload' => ['anchor' => $animation->position->value === 'screen' ? 'screen' : 'target'],
          ]]];
      }
    }
    $length = max([$animation->maxFrames, ...array_map(
      static fn(array $segment): int => $segment['endFrame'] + 1, $segments)]);
    if ($secondsPerFrame !== null) {
      // Import consumer-owned cadence onto the output lane, not a rounded FPS.
      $clock = new EffectPlaybackTiming(new CompiledEffectTimeline('legacy-' . $animation->id, '',
        fps: $fps, defaults: ['lengthFrames' => $length]), secondsPerFrame: $secondsPerFrame);
      foreach ($segments as &$segment) {
        $segment['startFrame'] = $clock->getFrameBoundary($segment['startFrame'], $fps);
        $segment['endFrame'] = $clock->getFrameBoundary($segment['endFrame'] + 1, $fps) - 1;
      }
      unset($segment);
      $segments = array_values(array_filter($segments,
        static fn(array $segment): bool => $segment['startFrame'] <= $segment['endFrame']));
      foreach ($cues as &$cue) {
        $cue['frame'] = $clock->getFrameBoundary($cue['frame'], $fps);
      }
      unset($cue);
      $length = max(1, $clock->getFrameBoundary($length, $fps));
    }
    return new CompiledEffectTimeline('legacy-' . $animation->id, '', fps: $fps,
      playbackSegments: $segments, cueSchedule: $cues,
      defaults: ['lengthFrames' => $length, 'restFrame' => $length - 1]);
  }
}
