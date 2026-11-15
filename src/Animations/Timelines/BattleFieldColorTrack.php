<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Battle\Presentation\BattleFieldColorState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use InvalidArgumentException;

/** One target timeline owns a complete field palette on the shared command clock. */
final class BattleFieldColorTrack
{
  public static function compile(array $track, int $length, string $playback, EffectCadence $cadence): array
  {
    CanvasCompositeValues::validateKeys($track, ['id', 'type', 'keyframes'], ['presentation']);
    if ($playback !== 'once' || $cadence !== EffectCadence::FIXED) {
      throw new InvalidArgumentException('Field colour requires a fixed, once-playing battle timeline.');
    }
    return ['id' => $track['id'], 'type' => 'field-color', 'presentation' => 'graphical',
      'keyframes' => EffectHeldSamples::compile($track['keyframes'], $length,
        static fn(array $payload): array => BattleFieldColorState::fromArray($payload)->toArray())];
  }

  /** References bind to the same graphical sequence; competing palette owners are refused. */
  public static function assertOwnership(array $tracks): void
  {
    $drawings = []; $samples = ['normal' => [], 'reduced' => []];
    foreach ($tracks as $track) {
      if (in_array($track['type'], ['image', 'glyph', 'text', 'trail', 'tether'], true)) { $drawings[$track['id']] = true; }
    }
    foreach ($tracks as $track) {
      if ($track['type'] !== 'field-color') { continue; }
      $scope = EffectMotionScope::parse($track['motion'] ?? 'all');
      foreach ($track['keyframes'] as $key) {
        foreach ($key['payload']['effects'] as $effect) {
          if (!isset($drawings[$effect['track']])) {
            throw new InvalidArgumentException('Field colour must select an existing graphical drawing track: ' . $effect['track']);
          }
        }
        if (!$key['visible']) { continue; }
        foreach (['normal' => EffectMotionScope::NORMAL, 'reduced' => EffectMotionScope::REDUCED] as $mode => $wanted) {
          if ($scope === EffectMotionScope::ALL || $scope === $wanted) { $samples[$mode][] = [$key['frame'], $key['frame'] + $key['duration']]; }
        }
      }
    }
    foreach ($samples as $mode => $intervals) {
      usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
      $end = 0;
      foreach ($intervals as [$start, $stop]) {
        if ($start < $end) { throw new InvalidArgumentException('Only one field colour state may own each ' . $mode . ' frame.'); }
        $end = $stop;
      }
    }
  }
}
