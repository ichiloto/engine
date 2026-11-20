<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** An authored held body tint replaces automatic reaction colour for its selected subjects. */
final class BattleBattlerTintTrack
{
  public static function compile(array $track, int $length, string $playback, EffectCadence $cadence): array
  {
    CanvasCompositeValues::validateKeys($track, ['id', 'type', 'anchor', 'keyframes'], ['presentation']);
    $anchor = is_string($track['anchor']) ? BattleMotionAnchor::tryFrom($track['anchor']) : null;
    if ($anchor === null || $playback !== 'once' || $cadence !== EffectCadence::FIXED) {
      throw new InvalidArgumentException('Battler tint requires a fixed once battle timeline and caster/target anchor.');
    }
    $keys = EffectHeldSamples::compile($track['keyframes'], $length, static function (array $payload) use ($anchor): array {
      CanvasCompositeValues::validateKeys($payload, ['color', 'strength']);
      return ['anchor' => $anchor->value, 'color' => PresentationColor::createFromArray($payload['color'])->toArray(),
        'strength' => EffectFlashStrength::getStrength($payload)];
    });
    return ['id' => $track['id'], 'type' => 'battler-tint', 'presentation' => 'graphical', 'keyframes' => $keys];
  }

  /** A frame has one held tint owner for each anchor; runtime also checks resolved identities. */
  public static function assertOwnership(array $tracks): void
  {
    $intervals = [];
    foreach ($tracks as $track) {
      if ($track['type'] !== 'battler-tint') { continue; }
      foreach ($track['keyframes'] as $key) {
        if ($key['visible']) { $intervals[$key['payload']['anchor']][] = [$key['frame'], $key['frame'] + $key['duration']]; }
      }
    }
    foreach ($intervals as $ranges) {
      usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
      $end = 0;
      foreach ($ranges as [$start, $stop]) {
        if ($start < $end) { throw new InvalidArgumentException('Only one authored battler tint may own each subject frame.'); }
        $end = $stop;
      }
    }
  }
}
