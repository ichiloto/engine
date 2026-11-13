<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\{BattleMotionAnchor, BattleMotionSpace, EffectPresentation};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** Pure held-pixel sampling, using instance identity and the command's paused clock. */
final class BattleBattlerMotion
{
  public static function getOffset(?BattleCommandPlayback $playback, CharacterInterface $battler,
    bool $partySide, bool $reducedMotion): ?Vector2
  {
    if ($playback === null || $reducedMotion || $playback->isCompleted || $playback->plan->resultsOnly) { return null; }
    $offset = null;
    foreach ($playback->session->getActiveSegments() as $segment) {
      if ($segment['layer'] !== 'battler-motion' || !EffectPresentation::GRAPHICAL->acceptsSegment($segment)) { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $data = $command['payload'];
        $matches = $data['anchor'] === BattleMotionAnchor::CASTER->value ? $battler === $playback->actor
          : in_array($battler, $playback->targets, true);
        if (!$matches) { continue; }
        $sign = $data['space'] === BattleMotionSpace::OPPONENTS->value ? BattleEffectDirection::getOpponentsSign($partySide) : 1;
        $offset ??= new Vector2();
        $offset->x += $data['x'] * $sign;
        $offset->y += $data['y'];
      }
    }
    return $offset;
  }
}
