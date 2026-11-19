<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/** A command-owned drawing transform; UI, battle geometry and results keep their own state. */
final class BattleFieldMotion
{
  /** Compatibility accessor for callers inspecting translation alone. */
  public static function getOffset(?BattleCommandPlayback $playback, bool $reducedMotion): ?Vector2
  {
    $sample = self::getTransform($playback, $reducedMotion);
    return $sample === null ? null : new Vector2($sample->x, $sample->y);
  }

  /** The visible camera samples whose density changes, indexed by authored frame.
   * These use the same track selection and combination as the painted field.
   * @return array<int, float>
   */
  public static function getScaledFrames(BattleCommandPlayback $playback, bool $reducedMotion): array
  {
    if ($reducedMotion || $playback->plan->resultsOnly) { return []; }
    $candidates = [];
    foreach ($playback->session->timeline->playbackSegments as $segment) {
      if ($segment['layer'] !== 'field-motion' || !EffectPresentation::GRAPHICAL->acceptsSegment($segment)) { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true) || ($command['payload']['scale'] ?? 1.0) === 1.0) { continue; }
        for ($frame = $segment['startFrame']; $frame <= $segment['endFrame']; $frame++) {
          $candidates[$frame] = true;
        }
        break;
      }
    }
    $scales = [];
    ksort($candidates, SORT_NUMERIC);
    foreach (array_keys($candidates) as $frame) {
      if ($playback->plan->getCinematicStageFrame($frame, false)?->active) { continue; }
      $transform = self::getTransform($playback, false, $frame);
      if ($transform !== null && $transform->scale !== 1.0) { $scales[$frame] = $transform->scale; }
    }
    return $scales;
  }

  /** Offsets add and explicit scale factors multiply, all about the same neutral field centre. */
  public static function getTransform(?BattleCommandPlayback $playback, bool $reducedMotion, ?int $frame = null): ?BattleFieldTransform
  {
    if ($playback === null || $reducedMotion || ($frame === null && $playback->isCompleted) || $playback->plan->resultsOnly) { return null; }
    $offset = null; $scale = 1.0;
    // Motion is sampled before visual clearBeforeDraw composition, on the same paused command clock.
    foreach ($playback->session->getActiveSegments($frame) as $segment) {
      if ($segment['layer'] !== 'field-motion' || !EffectPresentation::GRAPHICAL->acceptsSegment($segment)) { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $offset ??= new Vector2();
        $offset->x += $command['payload']['x'];
        $offset->y += $command['payload']['y'];
        $scale *= $command['payload']['scale'] ?? 1.0;
      }
    }
    return $offset === null ? null : new BattleFieldTransform($offset->x, $offset->y, $scale);
  }
}
