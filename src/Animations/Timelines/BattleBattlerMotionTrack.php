<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use InvalidArgumentException;

/** Rigid authored displacement belongs to stable battle participants, never game positions. */
final class BattleBattlerMotionTrack
{
  public static function compile(array $track, int $length, string $playback, EffectCadence $cadence): array
  {
    CanvasCompositeValues::validateKeys($track, ['id', 'type', 'anchor', 'keyframes'], ['presentation', 'space']);
    $anchor = is_string($track['anchor']) ? BattleMotionAnchor::tryFrom($track['anchor']) : null;
    $value = $track['space'] ?? BattleMotionSpace::CANVAS->value;
    $space = is_string($value) ? BattleMotionSpace::tryFrom($value) : null;
    if ($anchor === null || $space === null || $playback !== 'once' || $cadence !== EffectCadence::FIXED) {
      throw new InvalidArgumentException('Battler motion requires a fixed once timeline, caster/target anchor and canvas/opponents space.');
    }
    $keys = EffectMotionSamples::compile($track['keyframes'], $length);
    foreach ($keys as &$key) { $key['payload'] += ['anchor' => $anchor->value, 'space' => $space->value]; }
    unset($key);
    return ['id' => $track['id'], 'type' => 'battler-motion', 'presentation' => 'graphical', 'keyframes' => $keys];
  }
}
