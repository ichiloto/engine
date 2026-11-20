<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\{EffectMotionScope, EffectPresentation};
use RuntimeException;

/** Colour is sampled before visual clears; no drawing or gameplay state is changed here. */
final class BattleFieldColor
{
  public static function getState(?BattleCommandPlayback $playback, bool $reducedMotion): ?BattleFieldColorState
  {
    if ($playback === null || $playback->isCompleted || $playback->plan->resultsOnly) { return null; }
    $state = null;
    foreach (EffectMotionScope::filterSegments($playback->session->getActiveSegments(), $reducedMotion) as $segment) {
      if ($segment['layer'] !== 'field-color' || !EffectPresentation::GRAPHICAL->acceptsSegment($segment)) { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        if ($state !== null) { throw new RuntimeException('Competing field colour states reached the command host.'); }
        $state = BattleFieldColorState::fromArray($command['payload']);
      }
    }
    return $state;
  }
}
