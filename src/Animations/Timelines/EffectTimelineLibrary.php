<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Project-owned field and battle timelines; consumers own their anchors and gameplay. */
final class EffectTimelineLibrary
{
  public const string DIRECTORY = 'Animations';
  /** A timeline's stable identity: lowercase letters, digits, hyphens and underscores. */
  public const string ID_PATTERN = '/\A[a-z0-9][a-z0-9_-]*\z/';
  /** @var array<string, CompiledEffectTimeline> */
  private array $cache = [];

  public function __construct(public readonly string $assetRoot) {}

  public function load(string $id, bool $forBattle = false,
    EffectPresentation $presentation = EffectPresentation::GRAPHICAL): CompiledEffectTimeline
  {
    self::assertId($id);
    $key = ($forBattle ? 'battle:' : 'field:') . $presentation->value . ':' . $id;
    if (isset($this->cache[$key])) { return $this->cache[$key]; }
    $path = $this->assetRoot . '/' . self::DIRECTORY . "/{$id}/{$id}.timeline.php";
    $root = realpath($this->assetRoot);
    $file = realpath($path);
    if ($root === false || $file === false || !is_file($file)
      || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
      throw new InvalidArgumentException("Effect timeline {$id} was not found inside the asset root.");
    }
    $data = (static fn(string $file): mixed => require $file)($file);
    return $this->cache[$key] = $this->compile($id, $data, $forBattle, $presentation);
  }

  /**
   * Lists the timelines this asset root holds by stable identity, sorted:
   * every `Animations/<id>/<id>.timeline.php` with a valid id, inside the
   * asset root as load() requires. Listing reads no timeline; load() still
   * decides whether one can play.
   *
   * @return list<string>
   */
  public function findTimelineIds(): array
  {
    $root = realpath($this->assetRoot);
    $ids = [];

    foreach ($root === false ? [] : (glob($this->assetRoot . '/' . self::DIRECTORY . '/*/*.timeline.php') ?: []) as $path) {
      $id = basename(dirname($path));
      $file = realpath($path);

      if ($id === basename($path, '.timeline.php') && preg_match(self::ID_PATTERN, $id) === 1
        && $file !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        $ids[] = $id;
      }
    }

    sort($ids);
    return $ids;
  }

  public static function assertId(string $id): void
  {
    if (preg_match(self::ID_PATTERN, $id) !== 1) {
      throw new InvalidArgumentException('Effect identities use lowercase letters, digits, hyphens and underscores.');
    }
  }

  public function compile(string $id, mixed $data, bool $forBattle = false,
    EffectPresentation $presentation = EffectPresentation::GRAPHICAL): CompiledEffectTimeline
  {
    self::assertId($id);
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
      ...($forBattle ? ['effectTiming'] : [])]) !== []) {
      throw new InvalidArgumentException("Effect {$id} accepts fps, lengthFrames, playback, loopFrom, restFrame, tracks and presentation cues.");
    }
    $fps = $data['fps'] ?? null;
    $length = $data['lengthFrames'] ?? null;
    $rest = $data['restFrame'] ?? 0;
    $playback = $data['playback'] ?? 'once';
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
    $tracks = $data['tracks'] ?? null;
    if (!is_array($tracks) || !array_is_list($tracks) || $tracks === [] || count($tracks) > 32) {
      throw new InvalidArgumentException("Effect {$id} needs a list of 1..32 tracks.");
    }
    $ids = $normalized = [];
    $hasImages = $imageRestCovered = false;
    foreach ($tracks as $track) {
      if (!is_array($track) || !is_string($track['id'] ?? null) || isset($ids[$track['id']])
        || !in_array($track['type'] ?? null, ['image', 'glyph', 'text', ...($forBattle ? ['flash', 'shake'] : [])], true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid or duplicate track.");
      }
      self::assertId($track['id']);
      $scope = EffectPresentation::validateTrack($track['presentation'] ?? 'all');
      $ids[$track['id']] = true;
      // Unselected graphical resources are not dependencies of terminal playback.
      if (!$presentation->acceptsSegment(['layer' => $track['type'], 'presentation' => $scope])) { continue; }
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
          self::validateKeyframe($id, $keyframe);
          if (isset($track['anchor'])) { $keyframe['payload']['anchor'] = $track['anchor']; }
          if (isset($track['facing'])) { $keyframe['payload']['facing'] = $track['facing']; }
        }
        unset($keyframe);
        usort($track['keyframes'], static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
        $end = -1;
        foreach ($track['keyframes'] as $keyframe) {
          if ($keyframe['frame'] <= $end) { throw new InvalidArgumentException("Effect {$id} presentation track has overlapping keyframes."); }
          $end = $keyframe['frame'] + ($keyframe['duration'] ?? 1) - 1;
        }
        unset($track['facing']);
        $normalized[] = \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack::fromArray($track)->toArray();
        continue;
      }
      if (!is_array($track) || array_diff(array_keys($track), ['id', 'type', 'asset', 'sheet', 'cells', 'depth', 'keyframes',
        'presentation', ...($forBattle ? ['anchor', 'facing'] : [])]) !== []
        || ($track['type'] ?? null) !== 'image' || !is_string($track['id'] ?? null)) {
        throw new InvalidArgumentException("Effect {$id} currently accepts image tracks with id, asset, sheet, cells, depth and keyframes.");
      }
      $hasImages = true;
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
      if (isset($track['anchor']) && !in_array($track['anchor'], ['caster', 'target', 'screen'], true)) {
        throw new InvalidArgumentException("Effect {$id} has an invalid image anchor.");
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
      foreach ($frames as $frame) {
        if (!is_array($frame) || array_diff(array_keys($frame), ['frame', 'duration', 'sourceFrame', 'position', ...($forBattle ? ['flipX', 'flipY'] : [])]) !== []
          || (array_key_exists('flipX', $frame) && !is_bool($frame['flipX']))
          || (array_key_exists('flipY', $frame) && !is_bool($frame['flipY']))) {
          throw new InvalidArgumentException("Effect {$id} keyframes accept frame, duration, sourceFrame, position and boolean flipX/flipY.");
        }
        $at = $frame['frame'] ?? null;
        $duration = $frame['duration'] ?? 1;
        $source = $frame['sourceFrame'] ?? 0;
        $position = $frame['position'] ?? ['x' => 0, 'y' => 0];
        if (!is_int($at) || $at < 0 || !is_int($duration) || $duration < 1 || $at + $duration > $length
          || !is_int($source) || $source < 0 || $source >= $sheet['columns'] * $sheet['rows']
          || !is_array($position) || array_diff(array_keys($position), ['x', 'y']) !== []
          || !is_int($position['x'] ?? null) || !is_int($position['y'] ?? null)) {
          throw new InvalidArgumentException("Effect {$id} has an invalid keyframe range, sourceFrame or cell offset.");
        }
        $keyframes[] = ['frame' => $at, 'duration' => $duration, 'assetId' => $track['asset'], 'position' => $position,
          'payload' => ['sourceFrame' => $source, 'columns' => $sheet['columns'], 'rows' => $sheet['rows'],
            'frameWidth' => intdiv($size['width'], $sheet['columns']), 'frameHeight' => intdiv($size['height'], $sheet['rows']),
            'cells' => $cells, 'depth' => $depth, ...(!isset($track['anchor']) ? [] : ['anchor' => $track['anchor']]),
            ...(!isset($track['facing']) ? [] : ['facing' => $track['facing']]),
            ...(!array_key_exists('flipX', $frame) ? [] : ['flipX' => $frame['flipX']]),
            ...(!array_key_exists('flipY', $frame) ? [] : ['flipY' => $frame['flipY']])]];
      }
      usort($keyframes, static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
      $end = -1;
      foreach ($keyframes as $frame) {
        if ($frame['frame'] <= $end) { throw new InvalidArgumentException("Effect {$id} image track has overlapping keyframes."); }
        $end = $frame['frame'] + $frame['duration'] - 1;
      }
      $imageRestCovered = $imageRestCovered || array_any($keyframes,
        static fn(array $frame): bool => $frame['frame'] <= $rest && $rest < $frame['frame'] + $frame['duration']);
      $normalized[] = ['id' => $track['id'], 'type' => 'image', 'presentation' => $scope, 'keyframes' => $keyframes];
    }
    if ($hasImages && !$imageRestCovered) {
      throw new InvalidArgumentException("Effect {$id} image sequence has no authored rest presentation.");
    }
    $cues = $data['cues'] ?? [];
    if (!is_array($cues) || !array_is_list($cues) || count($cues) > 10000) {
      throw new InvalidArgumentException("Effect {$id} cues must be a bounded list.");
    }
    $cueIds = [];
    foreach ($cues as $cue) {
      if (!is_array($cue) || array_diff(array_keys($cue), ['id', 'frame', 'type', 'payload']) !== []
        || !is_string($cue['id'] ?? null) || isset($cueIds[$cue['id']])
        || !is_int($cue['frame'] ?? null) || $cue['frame'] < 0 || $cue['frame'] >= $length
        || !in_array($cue['type'] ?? '', $forBattle
          ? ['applyEffect', 'playSound', 'showMessage', 'flash', 'shake'] : ['playSound'], true)
        || !is_array($cue['payload'] ?? [])) {
        throw new InvalidArgumentException("Effect {$id} has an invalid " . ($forBattle ? 'battle' : 'field presentation') . ' cue.');
      }
      self::assertId($cue['id']);
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
        ...($timing === null ? [] : ['effectTiming' => $timing]),
        'playback' => ['loop' => $playback === 'loop', 'loopFrom' => $loopFrom]]);
  }

  private static function validateKeyframe(string $id, array $frame): void
  {
    if (array_diff(array_keys($frame), ['frame', 'duration', 'position', 'content', 'assetId', 'color',
      'visible', 'zIndex', 'payload']) !== [] || !is_array($frame['payload'] ?? [])) {
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
