<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Battle\Presentation\BattleEffectContactRail;
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
    $acceptsStage = $forBattle || $forSummon || $forStage;
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
      ...($forBattle ? ['effectTiming', 'cadence', 'poseSyncFrame', 'poseClock', 'rangeTiming', 'flightTiming', 'fallbackHitSpacingFrames'] : []), ...($acceptsStage ? ['stage'] : [])]) !== []) {
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
    $poseClockValue = array_key_exists('poseClock', $data) ? $data['poseClock'] : EffectPoseClock::ANNOUNCEMENT->value;
    $poseClock = is_string($poseClockValue) ? EffectPoseClock::tryFrom($poseClockValue) : null;
    if ($poseClock === null || ($poseClock === EffectPoseClock::TARGET
      && ($playback !== 'once' || $cadence !== EffectCadence::FIXED || $forSummon))) {
      throw new InvalidArgumentException("Effect {$id} target pose clock requires a fixed, once-playing battle effect.");
    }
    $poseSyncFrame = $data['poseSyncFrame'] ?? null;
    if (array_key_exists('poseSyncFrame', $data)
      && (!is_int($poseSyncFrame) || $poseSyncFrame < 0 || $poseSyncFrame >= $length || $playback !== 'once')) {
      throw new InvalidArgumentException("Effect {$id} poseSyncFrame must identify a frame of a once-playing battle effect.");
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
    if (!is_array($tracks) || !array_is_list($tracks) || (!$forSummon && !$forStage && $tracks === []) || count($tracks) > 32) {
      throw new InvalidArgumentException("Effect {$id} needs a list of 1..32 tracks.");
    }
    $ids = $normalized = [];
    $hasImages = $imageRestCovered = $stageRestCovered = false;
    foreach ($tracks as $track) {
      if (!is_array($track) || !is_string($track['id'] ?? null) || isset($ids[$track['id']])
        || !in_array($track['type'] ?? null, ['image', 'glyph', 'text', ...($forBattle ? ['flash', 'shake', 'tone'] : []), ...($forBattle && !$forSummon ? ['trail', 'tether'] : [])], true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid or duplicate track.");
      }
      EffectTimelineLibrary::assertId($track['id']);
      $graphicalOnly = in_array($track['type'], ['trail', 'tether'], true);
      $scope = EffectPresentation::validateTrack($track['presentation'] ?? ($graphicalOnly ? 'graphical' : 'all'));
      if ($graphicalOnly && $scope !== 'graphical') {
        throw new InvalidArgumentException("Effect {$id} {$track['type']} presentation must be graphical.");
      }
      $ids[$track['id']] = true;
      // Unselected graphical resources are not dependencies of terminal playback.
      if (!$presentation->acceptsSegment(['layer' => $track['type'], 'presentation' => $scope])) { continue; }
      if ($forStage && $presentation === EffectPresentation::GRAPHICAL
        && ($track['type'] !== 'image' || ($track['anchor'] ?? null) !== 'stage')) {
        throw new InvalidArgumentException('A caller-owned stage presentation accepts only stage image tracks.');
      }
      if ($track['type'] === 'trail') {
        $normalized[] = self::compileTrail($id, $track, $length, $playback, isset($data['rangeTiming']));
        continue;
      }
      if ($track['type'] === 'tether') {
        $tether = BattleTetherTrack::compile($this->assetRoot, $track, $length, $playback);
        $hasImages = true;
        $imageRestCovered = $imageRestCovered || array_any($tether['keyframes'],
          static fn(array $frame): bool => $frame['frame'] <= $rest && $rest < $frame['frame'] + $frame['duration']);
        $normalized[] = $tether;
        continue;
      }
      if (array_key_exists('facing', $track) && (!$forBattle
        || !in_array($track['type'], ['image', 'glyph'], true)
        || !in_array($track['facing'], ['east', 'west'], true)
        || ($track['anchor'] ?? 'target') === 'screen')) {
        throw new InvalidArgumentException("Effect {$id} facing requires an east/west battle image or glyph anchored to a battler.");
      }
      if (array_key_exists('toward', $track)) {
        EffectFacingTarget::parse($track['toward']);
        if (!array_key_exists('facing', $track)) {
          throw new InvalidArgumentException("Effect {$id} toward turns a directional track; declare its facing too.");
        }
      }
      if (in_array($track['type'] ?? '', ['glyph', 'text', 'flash', 'shake', 'tone'], true)) {
        if (array_diff(array_keys($track), ['id', 'type', 'anchor', 'keyframes', 'presentation', ...($forBattle ? ['facing', 'toward'] : [])]) !== []
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
          if ($track['type'] === 'tone') {
            $payload = $keyframe['payload'] ?? [];
            if (!in_array($track['anchor'] ?? 'target', ['caster', 'target'], true)
              || array_diff(array_keys($keyframe), ['frame', 'duration', 'visible', 'payload']) !== []
              || array_diff(array_keys($payload), ['brightness']) !== []
              || (!is_int($payload['brightness'] ?? null) && !is_float($payload['brightness'] ?? null))
              || !is_finite((float)($payload['brightness'] ?? NAN)) || $payload['brightness'] < 0 || $payload['brightness'] > 1) {
              throw new InvalidArgumentException("Effect {$id} tone requires a battler anchor and brightness within 0..1.");
            }
          }
          if (isset($track['anchor'])) { $keyframe['payload']['anchor'] = $track['anchor']; }
          if (isset($track['facing'])) { $keyframe['payload']['facing'] = $track['facing']; }
          if (isset($track['toward'])) { $keyframe['payload']['toward'] = $track['toward']; }
        }
        unset($keyframe);
        usort($track['keyframes'], static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
        $end = -1;
        foreach ($track['keyframes'] as $keyframe) {
          // Legacy summon glyphs may intentionally overlap; image crops remain exclusive.
          if (!$forSummon && $keyframe['frame'] <= $end) { throw new InvalidArgumentException("Effect {$id} presentation track has overlapping keyframes."); }
          $end = $keyframe['frame'] + ($keyframe['duration'] ?? 1) - 1;
        }
        unset($track['facing'], $track['toward']);
        $normalized[] = \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack::fromArray($track)->toArray();
        continue;
      }
      if (!is_array($track) || array_diff(array_keys($track), ['id', 'type', 'asset', 'sheet', 'cells', 'fit', 'depth', 'keyframes',
        'presentation', 'pivot', ...($forBattle ? ['anchor', 'facing', 'toward', 'attachment', 'attachmentPoint', 'detachFrame', 'flight', 'span', 'anchorLifetime', 'contactRail'] : []),
        ...($acceptsStage ? ['placement', 'zIndex'] : []), ...($forStage ? ['anchor'] : [])]) !== []
        || ($track['type'] ?? null) !== 'image' || !is_string($track['id'] ?? null)) {
        throw new InvalidArgumentException("Effect {$id} currently accepts image tracks with id, asset, sheet, cells, depth and keyframes.");
      }
      $onStage = ($track['anchor'] ?? null) === 'stage';
      if (isset($track['anchor']) && !in_array($track['anchor'], ['caster', 'target', 'screen',
        ...($forBattle ? ['target-group'] : []), ...($acceptsStage ? ['stage'] : [])], true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid image anchor.");
      }
      $depth = $track['depth'] ?? EffectImageDepth::FRONT->value;
      $imageDepth = is_string($depth) ? EffectImageDepth::tryFrom($depth) : null;
      if ($imageDepth === null) { throw new InvalidArgumentException("Effect {$id} has an invalid image depth."); }
      if ($imageDepth === EffectImageDepth::SUBJECT_FRONT && (!$forBattle
        || !EffectImageDepth::supportsSubjectOrder($track['anchor'] ?? 'target')
        || array_key_exists('detachFrame', $track) || array_key_exists('flight', $track))) {
        throw new InvalidArgumentException("Effect {$id} subject-front depth requires an attached caster or target battle image.");
      }
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
      $fit = \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageFit::parse(
        array_key_exists('fit', $track) ? $track['fit'] : 'stretch');
      if (array_key_exists('span', $track) && (!is_bool($track['span'])
        || ($track['anchor'] ?? null) !== 'target-group'
        || ($track['attachment'] ?? 'center') !== 'ground' || $fit->value !== 'contain')) {
        throw new InvalidArgumentException("Effect {$id} span requires a target-group ground image with contain fit and a boolean value.");
      }
      $anchorLifetime = $track['anchorLifetime'] ?? null;
      if (array_key_exists('anchorLifetime', $track)
        && (!is_string($anchorLifetime) || EffectAnchorLifetime::tryFrom($anchorLifetime) === null
          || ($track['anchor'] ?? null) !== 'target-group' || ($track['attachment'] ?? null) !== 'ground'
          || $playback !== 'once')) {
        throw new InvalidArgumentException("Effect {$id} anchorLifetime requires a once-playing target-group ground image and a known lifetime.");
      }
      $contactRail = null;
      if (array_key_exists('contactRail', $track)) {
        if (!$forBattle || $anchorLifetime !== EffectAnchorLifetime::EFFECT_START->value) {
          throw new InvalidArgumentException("Effect {$id} contactRail requires a battle target group captured at effect start.");
        }
        $contactRail = BattleEffectContactRail::parseSource($track['contactRail']);
      }
      if (array_key_exists('attachment', $track)) {
        EffectImageAttachment::parse($track['attachment']);
        if (($track['anchor'] ?? 'target') === 'screen') {
          throw new InvalidArgumentException("Effect {$id} attachment requires a battler image track, not a screen anchor.");
        }
      }
      if (array_key_exists('attachmentPoint', $track)) {
        if (!is_string($track['attachmentPoint'])
          || !in_array($track['anchor'] ?? 'target', ['caster', 'target'], true)
          || array_key_exists('attachment', $track)) {
          throw new InvalidArgumentException("Effect {$id} attachmentPoint requires a named point on a caster or target image without another attachment.");
        }
        EffectTimelineLibrary::assertId($track['attachmentPoint']);
      }
      if (array_key_exists('detachFrame', $track) && (!isset($track['attachmentPoint'])
        || !is_int($track['detachFrame']) || $track['detachFrame'] < 0 || $track['detachFrame'] >= $length
        || $playback !== 'once')) {
        throw new InvalidArgumentException("Effect {$id} detachFrame requires a named pose point and a valid frame in a once-playing battle effect.");
      }
      $flight = $track['flight'] ?? null;
      if (array_key_exists('flight', $track)) {
        self::validateFlight($id, $track, $flight, $length, isset($data['rangeTiming']));
      }
      if (isset($flight) && array_key_exists('tipX', $flight)) {
        $tip = $flight['tipX'];
        if (($flight['route'] ?? null) !== EffectFlightRoute::STRAIGHT->value || !isset($track['facing'])
          || (!is_int($tip) && !is_float($tip)) || !is_finite((float)$tip) || $tip < 0 || $tip > 1) {
          throw new InvalidArgumentException("Effect {$id} flight tipX requires a directional straight flight and a normalized source point.");
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
            ...(!isset($track['attachmentPoint']) ? [] : ['attachmentPoint' => $track['attachmentPoint']]),
            ...(!isset($track['detachFrame']) ? [] : ['detachFrame' => $track['detachFrame']]),
            ...(!isset($track['flight']) ? [] : ['flight' => $track['flight']]),
            ...(!array_key_exists('span', $track) ? [] : ['span' => $track['span']]),
            ...($anchorLifetime === null ? [] : ['anchorLifetime' => $anchorLifetime]),
            ...($contactRail === null ? [] : ['contactRail' => $contactRail]),
            ...(!isset($track['pivot']) ? [] : ['pivot' => $track['pivot']]),
            ...(!isset($track['facing']) ? [] : ['facing' => $track['facing']]),
            ...(!isset($track['toward']) ? [] : ['toward' => $track['toward']]),
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
    if ($stage !== null && !$stageRestCovered && !($forStage && $tracks === [])) {
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
    $mode = is_array($timing) && is_string($timing['mode'] ?? null) ? EffectImpactTimingMode::tryFrom($timing['mode']) : null;
    if ($timing !== null && ($mode === null
      || array_diff(array_keys($timing), ['mode', 'frame', 'cueId']) !== []
      || ($mode->usesFrame() && (!is_int($timing['frame'] ?? null)
        || $timing['frame'] < 0 || $timing['frame'] >= $length))
      || ($mode === EffectImpactTimingMode::CUE && !isset($cueIds[$timing['cueId'] ?? ''])))) {
      throw new InvalidArgumentException("Effect {$id} impact timing must identify a valid frame, cue or end.");
    }
    if ($mode === EffectImpactTimingMode::ARENA && ($playback !== 'once' || isset($timing['cueId'])
      || ($presentation === EffectPresentation::GRAPHICAL
        && ($stage === null || $timing['frame'] < $stage->data['restoreFrame'])))) {
      throw new InvalidArgumentException("Effect {$id} arena impact requires a once-playing stage and a frame at or after battlefield restoration.");
    }
    $spacing = $data['fallbackHitSpacingFrames'] ?? null;
    if (array_key_exists('fallbackHitSpacingFrames', $data) && (!is_int($spacing) || $spacing < 1 || $spacing > $fps
      || $playback !== 'once' || $cadence !== EffectCadence::FIXED)) {
      throw new InvalidArgumentException("Effect {$id} fallback hit spacing requires a fixed, once-playing effect and 1..fps authored frames.");
    }
    $spacedFlight = null;
    if ($spacing !== null && $presentation === EffectPresentation::GRAPHICAL) {
      $flights = array_values(array_filter($tracks, static fn(array $track): bool =>
        ($track['flight']['fallback'] ?? null) === EffectStraightFlightFallback::HORIZONTAL_EXIT->value
        && $presentation->acceptsSegment(['presentation' => $track['presentation'] ?? 'all'])));
      $spacedFlight = count($flights) === 1 ? $flights[0] : null;
      $impact = self::getAuthoredImpactFrame($cues, $timing);
      if ($spacedFlight === null || $spacedFlight['flight']['arrivalFrame'] !== $impact
        || ($spacedFlight['flight']['holdFrame'] ?? $impact) !== $impact
        || ($stage !== null && $spacedFlight['detachFrame'] < $stage->data['restoreFrame'])) {
        throw new InvalidArgumentException("Effect {$id} spaced fallback hits require one horizontal exit from the arena with arrival at impact.");
      }
    }
    $rangeTiming = null;
    if ($presentation === EffectPresentation::GRAPHICAL && array_key_exists('rangeTiming', $data)) {
      if ($stage !== null || $forSummon || $playback !== 'once' || $cadence !== EffectCadence::FIXED) {
        throw new InvalidArgumentException("Effect {$id} range timing requires a fixed, once-playing battle target without a cinematic stage.");
      }
      $fixed = array_column($cues, 'frame');
      if ($mode?->usesFrame()) { $fixed[] = $timing['frame']; }
      foreach ($tracks as $track) {
        if (is_int($track['flight']['arrivalFrame'] ?? null)) { $fixed[] = $track['flight']['arrivalFrame']; }
        if ($track['type'] === 'trail') { $fixed[] = $track['fadeEndFrame']; }
      }
      BattleEffectRangeTiming::fromArray($data['rangeTiming'], $length, $fixed);
      $rangeTiming = ['rangeTiming' => $data['rangeTiming'], 'rangeTimingFixedFrames' => $fixed];
    }
    $flightTiming = null;
    if ($presentation === EffectPresentation::GRAPHICAL && array_key_exists('flightTiming', $data)) {
      if ($stage !== null || $forSummon || $playback !== 'once' || $cadence !== EffectCadence::FIXED || $rangeTiming !== null) {
        throw new InvalidArgumentException("Effect {$id} flight timing requires a fixed, once-playing battle effect without a stage or range timing.");
      }
      $flightTiming = self::readFlightTiming($id, $data['flightTiming'], $tracks, $cues, $timing);
    }
    return new CompiledEffectTimeline($id, sha1(json_encode($data, JSON_THROW_ON_ERROR)), fps: $fps,
      playbackSegments: (new EffectTimelineCompiler())->compileTracks($normalized),
      cueSchedule: $cues,
      defaults: ['lengthFrames' => $length, 'restFrame' => $rest,
        ...($cadence === EffectCadence::FIXED ? [] : ['cadence' => $cadence->value]),
        ...($timing === null ? [] : ['effectTiming' => $timing]),
        ...($poseSyncFrame === null ? [] : ['poseSyncFrame' => $poseSyncFrame]),
        ...($poseClock === EffectPoseClock::ANNOUNCEMENT ? [] : ['poseClock' => $poseClock->value]),
        ...($rangeTiming ?? []),
        ...($spacedFlight === null ? [] : ['fallbackHitSpacingFrames' => $spacing, 'fallbackHitTrack' => $spacedFlight['id']]),
        ...($flightTiming === null ? [] : ['flightTiming' => $flightTiming]),
        'playback' => ['loop' => $playback === 'loop', 'loopFrom' => $loopFrom],
        ...($stage === null ? [] : ['stage' => $stage->data])]);
  }

  /** Whether this authored track can own distance-based flight timing. */
  public static function canTimeFlightFromTrack(mixed $track): bool
  {
    return is_array($track) && is_string($track['id'] ?? null) && ($track['type'] ?? null) === 'image'
      && ($track['anchor'] ?? null) === 'caster' && is_int($track['detachFrame'] ?? null)
      && is_array($track['flight'] ?? null) && ($track['presentation'] ?? 'graphical') === 'graphical';
  }

  /** @return array{track: string, release: int, arrival: int, speed: float} */
  private static function readFlightTiming(string $id, mixed $data, array $tracks, array $cues, ?array $timing): array
  {
    $trackId = is_array($data) ? ($data['track'] ?? null) : null;
    $speed = is_array($data) ? ($data['speed'] ?? null) : null;
    if (!is_array($data) || count($data) !== 2 || !is_string($trackId)
      || (!is_int($speed) && !is_float($speed)) || !is_finite((float)$speed) || $speed < .05 || $speed > 20) {
      throw new InvalidArgumentException("Effect {$id} flight timing needs a track ID and speed of 0.05..20 arena widths per second.");
    }
    $track = array_find($tracks, static fn(array $track): bool => ($track['id'] ?? null) === $trackId);
    $impact = self::getAuthoredImpactFrame($cues, $timing);
    if (!self::canTimeFlightFromTrack($track) || $impact !== $track['flight']['arrivalFrame']
      || ($track['flight']['holdFrame'] ?? $impact) !== $impact) {
      throw new InvalidArgumentException("Effect {$id} flight timing requires a graphical flight whose arrival owns the impact cue.");
    }
    return ['track' => $trackId, 'release' => $track['detachFrame'], 'arrival' => $impact, 'speed' => (float)$speed];
  }

  private static function getAuthoredImpactFrame(array $cues, ?array $timing): ?int
  {
    return match ($timing['mode'] ?? 'cue') {
      'frame', 'arena' => $timing['frame'],
      'cue' => array_find($cues, static fn(array $cue): bool => isset($timing['cueId'])
        ? $cue['id'] === $timing['cueId'] : $cue['type'] === 'applyEffect')['frame'] ?? null,
      default => null,
    };
  }

  private static function compileTrail(string $id, array $track, int $length, string $playback, bool $hasRange): array
  {
    $detach = $track['detachFrame'] ?? null;
    $end = $track['fadeEndFrame'] ?? null;
    $flight = $track['flight'] ?? null;
    $depth = $track['depth'] ?? 'front';
    if ($playback !== 'once' || ($track['presentation'] ?? 'graphical') !== 'graphical'
      || array_diff(array_keys($track), ['id', 'type', 'presentation', 'attachmentPoint', 'detachFrame',
        'fadeEndFrame', 'flight', 'profile', 'depth']) !== []
      || !is_string($track['attachmentPoint'] ?? null) || !is_int($detach) || $detach < 0 || $detach >= $length
      || !is_int($end) || $end > $length || $end <= ($flight['arrivalFrame'] ?? $length)
      || !is_array($track['profile'] ?? null) || !in_array($depth, ['behind', 'front'], true)
      || (is_array($flight) && array_intersect(array_keys($flight), ['holdFrame', 'tipX']) !== [])) {
      throw new InvalidArgumentException("Effect {$id} trail requires a graphical once-playing flight with a named pose point, fade end and profile.");
    }
    EffectTimelineLibrary::assertId($track['attachmentPoint']);
    self::validateFlight($id, [...$track, 'anchor' => 'caster'], $flight, $length, $hasRange);
    $profile = new BattleEffectTrail($track['profile']);
    $clearance = $flight['bystanderClearance'] ?? null;
    if (array_key_exists('bystanderClearance', $flight) && (($flight['route'] ?? null) !== EffectFlightRoute::CUBIC->value
      || (!is_int($clearance) && !is_float($clearance)) || !is_finite((float)$clearance) || $clearance < 0 || $clearance > 64)) {
      throw new InvalidArgumentException("Effect {$id} bystanderClearance requires a cubic trail and 0..64 logical pixels.");
    }
    return ['id' => $track['id'], 'type' => 'trail', 'presentation' => 'graphical', 'keyframes' => [[
      'frame' => $detach, 'duration' => $end - $detach, 'payload' => [
        'anchor' => 'caster', 'attachmentPoint' => $track['attachmentPoint'], 'detachFrame' => $detach,
        'fadeEndFrame' => $end, 'flight' => $flight, 'profile' => $profile->data, 'depth' => $depth,
      ],
    ]]];
  }

  private static function validateFlight(string $id, array $track, mixed $flight, int $length, bool $hasRange): void
  {
    if (($track['anchor'] ?? null) !== 'caster' || !isset($track['detachFrame'])
      || !is_array($flight) || array_diff(array_keys($flight), ['arrivalFrame', 'holdFrame', 'route', 'tipX', 'curve', 'farCurve', 'contact', 'fallback',
        ...($track['type'] === 'trail' ? ['bystanderClearance'] : [])]) !== []
      || !is_int($flight['arrivalFrame'] ?? null) || $flight['arrivalFrame'] <= $track['detachFrame']
      || $flight['arrivalFrame'] >= $length
      || (array_key_exists('route', $flight) && (!is_string($flight['route']) || EffectFlightRoute::tryFrom($flight['route']) === null))
      || (array_key_exists('holdFrame', $flight) && (!is_int($flight['holdFrame'])
        || $flight['holdFrame'] < $track['detachFrame'] || $flight['holdFrame'] > $flight['arrivalFrame']))) {
      throw new InvalidArgumentException("Effect {$id} flight requires a detached caster point, an arrival frame after release and an optional hold frame within flight.");
    }
    if (array_key_exists('contact', $flight) && (($flight['route'] ?? null) !== EffectFlightRoute::STRAIGHT->value
      || !is_string($flight['contact']) || EffectFlightContact::tryFrom($flight['contact']) === null)) {
      throw new InvalidArgumentException("Effect {$id} contact mode requires straight flight and edge or interior.");
    }
    if (array_key_exists('fallback', $flight) && (($flight['route'] ?? null) !== EffectFlightRoute::STRAIGHT->value
      || !is_string($flight['fallback']) || EffectStraightFlightFallback::tryFrom($flight['fallback']) === null)) {
      throw new InvalidArgumentException("Effect {$id} flight fallback requires a straight route and a supported policy.");
    }
    if (($flight['route'] ?? null) === EffectFlightRoute::CUBIC->value) {
      self::validateCurve($id, $flight['curve'] ?? null);
      if (array_key_exists('farCurve', $flight)) {
        if (!$hasRange) {
          throw new InvalidArgumentException("Effect {$id} farCurve requires battle range timing.");
        }
        self::validateCurve($id, $flight['farCurve']);
      }
    } elseif (array_key_exists('curve', $flight) || array_key_exists('farCurve', $flight)) {
      throw new InvalidArgumentException("Effect {$id} curve controls require cubic flight.");
    }
  }

  private static function validateCurve(string $id, mixed $curve): void
  {
    if (!is_array($curve) || count($curve) !== 4
      || array_diff(array_keys($curve), ['launchProgress', 'launchOffsetY', 'arrivalProgress', 'arrivalOffsetY']) !== []
      || array_any($curve, static fn($value): bool => (!is_int($value) && !is_float($value)) || !is_finite((float)$value))
      || $curve['launchProgress'] < 0 || $curve['arrivalProgress'] > 1 || $curve['launchProgress'] > $curve['arrivalProgress']
      || abs($curve['launchOffsetY']) > 1 || abs($curve['arrivalOffsetY']) > 1) {
      throw new InvalidArgumentException("Effect {$id} cubic flight requires ordered control progress within 0..1 and vertical offsets within -1..1.");
    }
  }

  private static function validateKeyframe(string $id, array $frame, bool $forSummon): void
  {
    if (array_diff(array_keys($frame), ['frame', 'duration', 'position', 'content', 'assetId', 'color',
      'visible', 'zIndex', 'payload', ...($forSummon ? ['blendMode', 'easing'] : [])]) !== [] || !is_array($frame['payload'] ?? [])) {
      throw new InvalidArgumentException("Effect {$id} has an invalid presentation keyframe payload.");
    }
    if (array_intersect(array_keys($frame['payload'] ?? []), ['facing', 'toward', 'flipX', 'flipY']) !== []) {
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
