<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Authored samples for a head and repeated links on one battle effect track. */
final class BattleTetherTrack
{
  public const int MAX_LINKS = 256;

  public static function compile(string $root, array $track, int $length, string $playback): array
  {
    CanvasCompositeValues::validateKeys($track,
      ['id', 'type', 'grip', 'head', 'headPivot', 'headHeight', 'links', 'linkHeight', 'linkSpacing', 'keyframes'],
      ['presentation', 'guide', 'guideGripLift', 'guideHandLift', 'guideGripOffset', 'guideHandOffset',
        'curveFirstProgress', 'curveSecondProgress', 'clearance', 'depth', 'linkDepth']);
    $depthValue = $track['depth'] ?? 'front';
    $linkDepthValue = $track['linkDepth'] ?? $depthValue;
    $depth = is_string($depthValue) ? EffectImageDepth::tryFrom($depthValue) : null;
    $linkDepth = is_string($linkDepthValue) ? EffectImageDepth::tryFrom($linkDepthValue) : null;
    $depths = [EffectImageDepth::FRONT, EffectImageDepth::BEHIND];
    if ($playback !== 'once' || ($track['presentation'] ?? 'graphical') !== 'graphical'
      || !in_array($depth, $depths, true) || !in_array($linkDepth, $depths, true)) {
      throw new InvalidArgumentException('A tether needs a once-playing graphical battle track with front or behind depth.');
    }
    foreach (['grip', ...(!array_key_exists('guide', $track) ? [] : ['guide'])] as $key) {
      if (!is_string($track[$key])) { throw new InvalidArgumentException('Tether pose points require names.'); }
      EffectTimelineLibrary::assertId($track[$key]);
    }
    foreach (['head', 'links'] as $key) {
      if (!is_string($track[$key])) { throw new InvalidArgumentException('Tether head and links require PNG asset paths.'); }
      SpriteValidation::validateAssetPath($track[$key]);
    }
    $links = PngAssetPreflight::inspect($root, $track['links']);
    PngAssetPreflight::inspect($root, $track['head']);
    if ($links['width'] % 2 !== 0 || !is_array($track['headPivot'])) {
      throw new InvalidArgumentException('Tether links need two equal-width cells and the head needs a normalized pivot.');
    }
    $config = [
      'grip' => $track['grip'], 'guide' => $track['guide'] ?? null, 'head' => $track['head'],
      'links' => $track['links'], 'headPivot' => PresentationSpritePivot::fromArray($track['headPivot'])->toArray(),
      'headHeight' => CanvasCompositeValues::getNumber($track['headHeight'], 1, 256),
      'linkHeight' => CanvasCompositeValues::getNumber($track['linkHeight'], 1, 32),
      'linkSpacing' => CanvasCompositeValues::getNumber($track['linkSpacing'], 1, 64),
      'guideGripLift' => CanvasCompositeValues::getNumber($track['guideGripLift'] ?? 0, -128, 128),
      'guideHandLift' => CanvasCompositeValues::getNumber($track['guideHandLift'] ?? 0, -128, 128),
      'guideGripOffset' => CanvasCompositeValues::getNumber($track['guideGripOffset'] ?? 0, -128, 128),
      'guideHandOffset' => CanvasCompositeValues::getNumber($track['guideHandOffset'] ?? 0, -128, 128),
      'curveFirstProgress' => CanvasCompositeValues::getNumber($track['curveFirstProgress'] ?? 1 / 3, 0, 1),
      'curveSecondProgress' => CanvasCompositeValues::getNumber($track['curveSecondProgress'] ?? 2 / 3, 0, 1),
      'clearance' => CanvasCompositeValues::getNumber($track['clearance'] ?? 0, 0, 64),
      'depth' => $depth->value, 'linkDepth' => $linkDepth->value,
    ];
    if ($config['curveFirstProgress'] > $config['curveSecondProgress']) {
      throw new InvalidArgumentException('Tether curve controls require ordered progress from guide to head.');
    }
    $frames = CanvasCompositeValues::getList($track['keyframes'], 1, min(10000, $length));
    $keyframes = [];
    $end = 0;
    foreach ($frames as $frame) {
      CanvasCompositeValues::validateKeys($frame, ['frame', 'x', 'y', 'targetWeight', 'rotation', 'lift'],
        ['duration', 'guideWeight', 'approach']);
      $start = $frame['frame'];
      $duration = $frame['duration'] ?? 1;
      if (!is_int($start) || !is_int($duration) || $duration < 1 || $start < $end || $start >= $length
        || $duration > $length - $start || !is_int($frame['rotation']) || $frame['rotation'] < 0 || $frame['rotation'] > 359) {
        throw new InvalidArgumentException('Tether samples need ordered nonoverlapping frames and a rotation in 0..359 degrees.');
      }
      $guideWeight = CanvasCompositeValues::getNumber($frame['guideWeight'] ?? 0, 0, 1);
      if ($guideWeight > 0 && $config['guide'] === null) {
        throw new InvalidArgumentException('A tether sample using a guide needs a named guide point.');
      }
      $keyframes[] = ['frame' => $start, 'duration' => $duration, 'payload' => [
        'anchor' => 'caster', ...$config,
        'x' => CanvasCompositeValues::getNumber($frame['x'], -1440, 1440),
        'y' => CanvasCompositeValues::getNumber($frame['y'], -1440, 1440),
        'targetWeight' => CanvasCompositeValues::getNumber($frame['targetWeight'], 0, 1),
        'rotation' => $frame['rotation'], 'guideWeight' => $guideWeight,
        'lift' => CanvasCompositeValues::getNumber($frame['lift'], -720, 720),
        'approach' => CanvasCompositeValues::getNumber($frame['approach'] ?? 0, 0, 1),
      ]];
      $end = $start + $duration;
    }
    return ['id' => $track['id'], 'type' => 'tether', 'presentation' => 'graphical', 'keyframes' => $keyframes];
  }
}
