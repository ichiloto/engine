<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;

/** One validation and normalization boundary for field, battle and summon tracks. */
final class EffectTimelineValidator
{
  public function __construct(public readonly string $assetRoot) {}

  public function compile(string $id, mixed $data, bool $forBattle = false,
    EffectPresentation $presentation = EffectPresentation::GRAPHICAL, bool $forSummon = false,
    bool $forStage = false): CompiledEffectTimeline
  {
    if ($forStage && ($forBattle || $forSummon)) {
      throw new InvalidArgumentException('Standalone stage admission is independent of battle and summon profiles.');
    }
    $acceptsStage = $forSummon || $forStage;
    EffectTimelineLibrary::assertId($id);
    if (is_array($data) && array_key_exists('presentations', $data)) {
      $variants = $data['presentations'];
      if (array_keys($data) !== ['presentations'] || !is_array($variants)
        || count($variants) !== 2 || array_diff(array_keys($variants), ['terminal', 'graphical']) !== []
        || !is_array($variants['terminal'] ?? null) || !is_array($variants['graphical'] ?? null)
        || isset($variants['terminal']['presentations']) || isset($variants['graphical']['presentations'])) {
        throw new InvalidArgumentException("Effect {$id} presentations must contain one terminal and one graphical sequence, without nesting.");
      }
      $data = $variants[$presentation->value];
    }
    if (!is_array($data) || array_diff(array_keys($data), ['fps', 'lengthFrames', 'playback', 'loopFrom', 'restFrame', 'tracks', 'cues',
      ...($forBattle ? ['effectTiming', 'cadence'] : []), ...($acceptsStage ? ['stage'] : [])]) !== []) {
      throw new InvalidArgumentException("Effect {$id} accepts fps, lengthFrames, playback, loopFrom, restFrame, tracks and presentation cues.");
    }
    $length = $data['lengthFrames'] ?? null;
    $rest = $data['restFrame'] ?? 0;
    $playback = $data['playback'] ?? 'once';
    $cadence = EffectCadence::parse(array_key_exists('cadence', $data) ? $data['cadence'] : 'fixed');
    if ($cadence === EffectCadence::BATTLE_PHASE && array_key_exists('fps', $data)) {
      throw new InvalidArgumentException("Effect {$id} battle-phase cadence must omit fps; its consumer owns timing.");
    }
    // Paced sequences keep the compiled shape; this nominal rate is not authored timing.
    $fps = $cadence === EffectCadence::BATTLE_PHASE ? 120 : ($data['fps'] ?? null);
    if ($cadence === EffectCadence::BATTLE_PHASE && $playback !== 'once') {
      throw new InvalidArgumentException("Effect {$id} battle-phase cadence requires once playback.");
    }
    if (!is_int($fps) || $fps < 1 || $fps > 120 || !is_int($length) || $length < 1 || $length > 100000
      || !is_int($rest) || $rest < 0 || $rest >= $length || !in_array($playback, ['once', 'loop'], true)) {
      throw new InvalidArgumentException("Effect {$id} needs fps 1..120, lengthFrames 1..100000, a restFrame in that range and once or loop playback.");
    }
    // A loop may restart from a later frame, so the frames before it play
    // once as an opening, as a balloon pops open and then idles.
    $loopFrom = $data['loopFrom'] ?? 0;
    if (!is_int($loopFrom) || $loopFrom < 0 || $loopFrom >= $length || ($loopFrom > 0 && $playback !== 'loop')) {
      throw new InvalidArgumentException("Effect {$id} loopFrom must be a frame of a looping effect.");
    }
    $stage = null;
    if ($acceptsStage && $presentation === EffectPresentation::GRAPHICAL && array_key_exists('stage', $data)) {
      if (!is_array($data['stage'])) { throw new InvalidArgumentException('Cinematic stage must be a descriptor.'); }
      $stage = CinematicStage::fromArray($data['stage'], $length, $rest);
      if ($cadence !== EffectCadence::FIXED) { throw new InvalidArgumentException('Cinematic stage requires its own fixed authored clock.'); }
    }
    if ($forStage && $presentation === EffectPresentation::GRAPHICAL && ($stage === null || $playback !== 'once')) {
      throw new InvalidArgumentException('A caller-owned stage presentation needs a stage and once playback.');
    }
    $tracks = $data['tracks'] ?? null;
    if (!is_array($tracks) || !array_is_list($tracks) || (!$forSummon && $tracks === []) || count($tracks) > 32) {
      throw new InvalidArgumentException("Effect {$id} needs a list of 1..32 tracks.");
    }
    $ids = $normalized = [];
    $hasImages = $imageRestCovered = $stageRestCovered = false;
    foreach ($tracks as $track) {
      if (!is_array($track) || !is_string($track['id'] ?? null) || isset($ids[$track['id']])
        || !in_array($track['type'] ?? null, ['image', 'glyph', 'text', ...($forBattle ? ['flash', 'shake'] : [])], true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid or duplicate track.");
      }
      EffectTimelineLibrary::assertId($track['id']);
      $scope = EffectPresentation::validateTrack($track['presentation'] ?? 'all');
      $ids[$track['id']] = true;
      // Unselected graphical resources are not dependencies of terminal playback.
      if (!$presentation->acceptsSegment(['layer' => $track['type'], 'presentation' => $scope])) { continue; }
      if ($forStage && $presentation === EffectPresentation::GRAPHICAL
        && ($track['type'] !== 'image' || ($track['anchor'] ?? null) !== 'stage')) {
        throw new InvalidArgumentException('A caller-owned stage presentation accepts only stage image tracks.');
      }
      if (array_key_exists('facing', $track) && (!$forBattle
        || !in_array($track['type'], ['image', 'glyph'], true)
        || !in_array($track['facing'], ['east', 'west'], true)
        || ($track['anchor'] ?? 'target') === 'screen')) {
        throw new InvalidArgumentException("Effect {$id} facing requires an east/west battle image or glyph anchored to a battler.");
      }
      if (in_array($track['type'] ?? '', ['glyph', 'text', 'flash', 'shake'], true)) {
        if (array_diff(array_keys($track), ['id', 'type', 'anchor', 'keyframes', 'presentation', ...($forBattle ? ['facing'] : [])]) !== []
          || !in_array($track['anchor'] ?? 'target', ['caster', 'target', 'screen'], true)
          || !is_array($track['keyframes'] ?? null) || !array_is_list($track['keyframes'])
          || $track['keyframes'] === [] || count($track['keyframes']) > 10000) {
          throw new InvalidArgumentException("Effect {$id} has an invalid presentation track.");
        }
        foreach ($track['keyframes'] as &$keyframe) {
          if (!is_array($keyframe) || !is_int($keyframe['frame'] ?? null) || $keyframe['frame'] < 0
            || !is_int($keyframe['duration'] ?? 1) || ($keyframe['duration'] ?? 1) < 1
            || $keyframe['frame'] + ($keyframe['duration'] ?? 1) > $length) {
            throw new InvalidArgumentException("Effect {$id} has an invalid presentation keyframe range.");
          }
          self::validateKeyframe($id, $keyframe, $forSummon);
          if (isset($track['anchor'])) { $keyframe['payload']['anchor'] = $track['anchor']; }
          if (isset($track['facing'])) { $keyframe['payload']['facing'] = $track['facing']; }
        }
        unset($keyframe);
        usort($track['keyframes'], static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
        $end = -1;
        foreach ($track['keyframes'] as $keyframe) {
          // Legacy summon glyphs may intentionally overlap; image crops remain exclusive.
          if (!$forSummon && $keyframe['frame'] <= $end) { throw new InvalidArgumentException("Effect {$id} presentation track has overlapping keyframes."); }
          $end = $keyframe['frame'] + ($keyframe['duration'] ?? 1) - 1;
        }
        unset($track['facing']);
        $normalized[] = \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack::fromArray($track)->toArray();
        continue;
      }
      if (!is_array($track) || array_diff(array_keys($track), ['id', 'type', 'asset', 'sheet', 'cells', 'fit', 'depth', 'keyframes',
        'presentation', 'pivot', ...($forBattle ? ['anchor', 'facing', 'attachment'] : []),
        ...($acceptsStage ? ['placement', 'zIndex'] : []), ...($forStage ? ['anchor'] : [])]) !== []
        || ($track['type'] ?? null) !== 'image' || !is_string($track['id'] ?? null)) {
        throw new InvalidArgumentException("Effect {$id} currently accepts image tracks with id, asset, sheet, cells, depth and keyframes.");
      }
      $onStage = ($track['anchor'] ?? null) === 'stage';
      $trackPath = 'tracks[' . $track['id'] . ']';
      $zIndex = array_key_exists('zIndex', $track) ? $track['zIndex'] : 0;
      if ($onStage && ($stage === null || array_intersect(array_keys($track), ['cells', 'facing', 'attachment']) !== [])) {
        throw new InvalidArgumentException('Stage images need a graphical stage, placement and bounded zIndex, not battler geometry.');
      }
      if ($onStage && (!is_int($zIndex) || abs($zIndex) > 500)) {
        throw new InvalidArgumentException($trackPath . '.zIndex must be an integer in -500..500.');
      }
      if (!$onStage && (array_key_exists('placement', $track) || array_key_exists('zIndex', $track))) {
        throw new InvalidArgumentException('Cinematic placement and zIndex require a stage image anchor.');
      }
      $placement = $onStage ? CinematicStage::validatePlacement($track['placement'] ?? null, $stage, $trackPath . '.placement') : null;
      $hasImages = $hasImages || !$onStage;
      if (!is_string($track['asset'] ?? null)) { throw new InvalidArgumentException("Effect {$id} image track needs an asset path."); }
      SpriteValidation::validateAssetPath($track['asset']);
      $sheet = $track['sheet'] ?? ['columns' => 1, 'rows' => 1];
      $cells = $track['cells'] ?? ['width' => 1, 'height' => 1];
      foreach ([[$sheet, ['columns', 'rows']], [$cells, ['width', 'height']]] as [$geometry, $keys]) {
        if (!is_array($geometry) || array_diff(array_keys($geometry), $keys) !== []
          || array_any($keys, static fn(string $key): bool => !is_int($geometry[$key] ?? null) || $geometry[$key] < 1 || $geometry[$key] > 64)) {
          throw new InvalidArgumentException("Effect {$id} sheet and cells require two positive integers no greater than 64.");
        }
      }
      $depth = $track['depth'] ?? 'front';
      if (!in_array($depth, ['behind', 'front'], true)) { throw new InvalidArgumentException("Effect {$id} depth must be behind or front."); }
      $fit = \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageFit::parse(
        array_key_exists('fit', $track) ? $track['fit'] : 'stretch');
      if (isset($track['anchor']) && !in_array($track['anchor'], ['caster', 'target', 'screen', ...($acceptsStage ? ['stage'] : [])], true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid image anchor.");
      }
      if (array_key_exists('attachment', $track)) {
        EffectImageAttachment::parse($track['attachment']);
        if (($track['anchor'] ?? 'target') === 'screen') {
          throw new InvalidArgumentException("Effect {$id} attachment requires a battler image track, not a screen anchor.");
        }
      }
      if (array_key_exists('pivot', $track)) {
        $pivot = $track['pivot'];
        if (!is_array($pivot)) {
          throw new InvalidArgumentException("Effect {$id} image pivot requires normalized x/y coordinates.");
        }
        PresentationSpritePivot::fromArray($pivot);
      }
      $size = PngAssetPreflight::inspect($this->assetRoot, $track['asset']);
      if ($size['width'] % $sheet['columns'] !== 0 || $size['height'] % $sheet['rows'] !== 0) {
        throw new InvalidArgumentException("Effect {$id} image does not divide into its sheet grid.");
      }
      $frames = $track['keyframes'] ?? null;
      if (!is_array($frames) || !array_is_list($frames) || $frames === [] || count($frames) > 10000) {
        throw new InvalidArgumentException("Effect {$id} image track needs a list of keyframes.");
      }
      $keyframes = [];
      foreach ($frames as $frameIndex => $frame) {
        $framePath = $trackPath . '.keyframes[' . $frameIndex . ']';
        if (!is_array($frame) || array_diff(array_keys($frame), ['frame', 'duration', 'sourceFrame', 'position',
          ...($forBattle || $forStage ? ['flipX', 'flipY'] : []), ...($onStage ? ['opacity'] : [])]) !== []
          || (array_key_exists('flipX', $frame) && !is_bool($frame['flipX']))
          || (array_key_exists('flipY', $frame) && !is_bool($frame['flipY']))) {
          throw new InvalidArgumentException("Effect {$id} keyframes accept frame, duration, sourceFrame, position and boolean flipX/flipY.");
        }
        $at = $frame['frame'] ?? null;
        $duration = $frame['duration'] ?? 1;
        $source = $frame['sourceFrame'] ?? 0;
        $position = array_key_exists('position', $frame) ? $frame['position'] : ['x' => 0, 'y' => 0];
        if ($onStage) { $position = CinematicStage::validatePoint($position, path: $framePath . '.position'); }
        if (!is_int($at) || $at < 0 || !is_int($duration) || $duration < 1 || $at + $duration > $length
          || !is_int($source) || $source < 0 || $source >= $sheet['columns'] * $sheet['rows']
          || !is_array($position) || array_diff(array_keys($position), ['x', 'y']) !== []
          || (!$onStage && (!is_int($position['x'] ?? null) || !is_int($position['y'] ?? null)))) {
          throw new InvalidArgumentException("Effect {$id} has an invalid keyframe range, sourceFrame or cell offset.");
        }
        $keyframes[] = ['frame' => $at, 'duration' => $duration, 'assetId' => $track['asset'], 'position' => $position,
          'payload' => ['sourceFrame' => $source, 'columns' => $sheet['columns'], 'rows' => $sheet['rows'],
            'frameWidth' => intdiv($size['width'], $sheet['columns']), 'frameHeight' => intdiv($size['height'], $sheet['rows']),
            'cells' => $cells, 'depth' => $depth, ...(isset($track['fit']) ? ['fit' => $fit->value] : []),
            ...(!isset($track['anchor']) ? [] : ['anchor' => $track['anchor']]),
            ...(!isset($track['attachment']) ? [] : ['attachment' => $track['attachment']]),
            ...(!isset($track['pivot']) ? [] : ['pivot' => $track['pivot']]),
            ...(!isset($track['facing']) ? [] : ['facing' => $track['facing']]),
            ...(!array_key_exists('flipX', $frame) ? [] : ['flipX' => $frame['flipX']]),
            ...(!array_key_exists('flipY', $frame) ? [] : ['flipY' => $frame['flipY']]),
            ...(!$onStage ? [] : ['placement' => $placement, 'zIndex' => $zIndex,
              'opacity' => CinematicStage::validateNumber(array_key_exists('opacity', $frame) ? $frame['opacity'] : 1, 0, 1, $framePath . '.opacity')])]];
      }
      usort($keyframes, static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
      $end = -1;
      foreach ($keyframes as $frame) {
        if ($frame['frame'] <= $end) { throw new InvalidArgumentException("Effect {$id} image track has overlapping keyframes."); }
        $end = $frame['frame'] + $frame['duration'] - 1;
      }
      if ($onStage) {
        $stageRestCovered = $stageRestCovered || array_any($keyframes,
          static fn(array $frame): bool => $frame['frame'] <= $stage->restFrame
            && $stage->restFrame < $frame['frame'] + $frame['duration']);
      } else {
        $imageRestCovered = $imageRestCovered || array_any($keyframes,
          static fn(array $frame): bool => $frame['frame'] <= $rest && $rest < $frame['frame'] + $frame['duration']);
      }
      $normalized[] = ['id' => $track['id'], 'type' => 'image', 'presentation' => $scope, 'keyframes' => $keyframes];
    }
    if ($hasImages && !$imageRestCovered) {
      throw new InvalidArgumentException("Effect {$id} image sequence has no authored rest presentation.");
    }
    if ($stage !== null && !$stageRestCovered) {
      throw new InvalidArgumentException('Cinematic stage needs image content at its safe reduced-motion frame.');
    }
    $cues = $data['cues'] ?? [];
    if (!is_array($cues) || !array_is_list($cues) || count($cues) > 10000) {
      throw new InvalidArgumentException("Effect {$id} cues must be a bounded list.");
    }
    if ($forStage && $cues !== []) {
      throw new InvalidArgumentException('Caller-owned stage presentations carry no cues; their consumers own audio and gameplay.');
    }
    $cueIds = [];
    foreach ($cues as $cue) {
      if (!is_array($cue) || array_diff(array_keys($cue), ['id', 'frame', 'type', 'payload']) !== []
        || !is_string($cue['id'] ?? null) || isset($cueIds[$cue['id']])
        || !is_int($cue['frame'] ?? null) || $cue['frame'] < 0 || $cue['frame'] >= $length
        || !in_array($cue['type'] ?? '', $forBattle
          ? ['applyEffect', 'playSound', 'showMessage', 'flash', 'shake', ...($forSummon ? ['restoreBattlefield'] : [])] : ['playSound'], true)
        || !is_array($cue['payload'] ?? [])) {
        throw new InvalidArgumentException("Effect {$id} has an invalid " . ($forBattle ? 'battle' : 'field presentation') . ' cue.');
      }
      EffectTimelineLibrary::assertId($cue['id']);
      self::validatePayload($id, $cue['payload'] ?? []);
      $cueIds[$cue['id']] = true;
    }
    usort($cues, static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
    $timing = $data['effectTiming'] ?? null;
    if ($timing !== null && (!is_array($timing)
      || !in_array($timing['mode'] ?? '', ['end', 'frame', 'cue'], true)
      || array_diff(array_keys($timing), ['mode', 'frame', 'cueId']) !== []
      || (($timing['mode'] ?? '') === 'frame' && (!is_int($timing['frame'] ?? null)
        || $timing['frame'] < 0 || $timing['frame'] >= $length))
      || (($timing['mode'] ?? '') === 'cue' && !isset($cueIds[$timing['cueId'] ?? ''])))) {
      throw new InvalidArgumentException("Effect {$id} impact timing must identify a valid frame, cue or end.");
    }
    return new CompiledEffectTimeline($id, sha1(json_encode($data, JSON_THROW_ON_ERROR)), fps: $fps,
      playbackSegments: (new EffectTimelineCompiler())->compileTracks($normalized),
      cueSchedule: $cues,
      defaults: ['lengthFrames' => $length, 'restFrame' => $rest,
        ...($cadence === EffectCadence::FIXED ? [] : ['cadence' => $cadence->value]),
        ...($timing === null ? [] : ['effectTiming' => $timing]),
        'playback' => ['loop' => $playback === 'loop', 'loopFrom' => $loopFrom],
        ...($stage === null ? [] : ['stage' => $stage->data])]);
  }

  private static function validateKeyframe(string $id, array $frame, bool $forSummon): void
  {
    if (array_diff(array_keys($frame), ['frame', 'duration', 'position', 'content', 'assetId', 'color',
      'visible', 'zIndex', 'payload', ...($forSummon ? ['blendMode', 'easing'] : [])]) !== [] || !is_array($frame['payload'] ?? [])) {
      throw new InvalidArgumentException("Effect {$id} has an invalid presentation keyframe payload.");
    }
    if (array_intersect(array_keys($frame['payload'] ?? []), ['facing', 'flipX', 'flipY']) !== []) {
      throw new InvalidArgumentException("Effect {$id} orientation belongs to the track, not an untyped glyph payload.");
    }
    foreach (['content', 'assetId', 'color'] as $key) {
      if (isset($frame[$key]) && (!is_string($frame[$key]) || strlen($frame[$key]) > 65536)) {
        throw new InvalidArgumentException("Effect {$id} presentation keyframe {$key} must be bounded text.");
      }
    }
    if ((isset($frame['visible']) && !is_bool($frame['visible']))
      || (isset($frame['zIndex']) && (!is_int($frame['zIndex']) || abs($frame['zIndex']) > 10000))) {
      throw new InvalidArgumentException("Effect {$id} has invalid presentation visibility or depth.");
    }
    if (isset($frame['position']) && (!is_array($frame['position'])
      || array_diff(array_keys($frame['position']), ['x', 'y']) !== []
      || !is_int($frame['position']['x'] ?? null) || !is_int($frame['position']['y'] ?? null)
      || abs($frame['position']['x']) > 16384 || abs($frame['position']['y']) > 16384)) {
      throw new InvalidArgumentException("Effect {$id} presentation positions require bounded integer cell offsets.");
    }
    self::validatePayload($id, $frame['payload'] ?? []);
  }

  private static function validatePayload(string $id, array $payload): void
  {
    foreach (['anchor' => ['caster', 'target', 'screen'], 'scope' => ['target', 'screen'],
      'legacyPosition' => ['head', 'center', 'feet', 'screen']] as $key => $values) {
      if (isset($payload[$key]) && !in_array($payload[$key], $values, true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid {$key}.");
      }
    }
    foreach (['soundEffect', 'sound', 'assetId', 'text', 'message', 'color'] as $key) {
      if (isset($payload[$key]) && (!is_string($payload[$key]) || strlen($payload[$key]) > 65536)) {
        throw new InvalidArgumentException("Effect {$id} cue {$key} must be bounded text.");
      }
    }
    foreach (['duration', 'durationFrames', 'amplitude'] as $key) {
      if (isset($payload[$key]) && (!is_int($payload[$key]) || $payload[$key] < 0 || $payload[$key] > 100000)) {
        throw new InvalidArgumentException("Effect {$id} cue {$key} must be a bounded non-negative integer.");
      }
    }
  }
}
