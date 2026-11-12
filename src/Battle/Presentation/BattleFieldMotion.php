<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/** A command-owned drawing transform; UI, battle geometry and results keep their own state. */
final class BattleFieldMotion
{
  public static function getOffset(?BattleCommandPlayback $playback, bool $reducedMotion): ?Vector2
  {
    if ($playback === null || $reducedMotion || $playback->isCompleted || $playback->plan->resultsOnly) { return null; }
    $offset = null;
    // Motion is sampled before visual clearBeforeDraw composition, on the same paused command clock.
    foreach ($playback->session->getActiveSegments() as $segment) {
      if ($segment['layer'] !== 'field-motion' || !EffectPresentation::GRAPHICAL->acceptsSegment($segment)) { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $offset ??= new Vector2();
        $offset->x += $command['payload']['x'];
        $offset->y += $command['payload']['y'];
      }
    }
    return $offset;
  }
}
