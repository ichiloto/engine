<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Project-owned ambient image timelines; no gameplay or terminal geometry. */
final class EffectTimelineLibrary
{
  public const string DIRECTORY = 'Animations';
  /** @var array<string, CompiledEffectTimeline> */
  private array $cache = [];

  public function __construct(public readonly string $assetRoot) {}

  public function load(string $id): CompiledEffectTimeline
  {
    self::assertId($id);
    if (isset($this->cache[$id])) { return $this->cache[$id]; }
    $path = $this->assetRoot . '/' . self::DIRECTORY . "/{$id}/{$id}.timeline.php";
    if (!is_file($path)) { throw new InvalidArgumentException("Effect timeline {$id} was not found."); }
    $data = (static fn(string $file): mixed => require $file)($path);
    return $this->cache[$id] = $this->compile($id, $data);
  }

  public static function assertId(string $id): void
  {
    if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
      throw new InvalidArgumentException('Effect identities use lowercase letters, digits, hyphens and underscores.');
    }
  }

  public function compile(string $id, mixed $data): CompiledEffectTimeline
  {
    self::assertId($id);
    if (!is_array($data) || array_diff(array_keys($data), ['fps', 'lengthFrames', 'playback', 'loopFrom', 'restFrame', 'tracks']) !== []) {
      throw new InvalidArgumentException("Effect {$id} accepts fps, lengthFrames, playback, loopFrom, restFrame and tracks.");
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
    foreach ($tracks as $track) {
      if (!is_array($track) || array_diff(array_keys($track), ['id', 'type', 'asset', 'sheet', 'cells', 'depth', 'keyframes']) !== []
        || ($track['type'] ?? null) !== 'image' || !is_string($track['id'] ?? null)) {
        throw new InvalidArgumentException("Effect {$id} currently accepts image tracks with id, asset, sheet, cells, depth and keyframes.");
      }
      self::assertId($track['id']);
      if (isset($ids[$track['id']])) { throw new InvalidArgumentException("Effect {$id} repeats track {$track['id']}."); }
      $ids[$track['id']] = true;
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
        if (!is_array($frame) || array_diff(array_keys($frame), ['frame', 'duration', 'sourceFrame', 'position']) !== []) {
          throw new InvalidArgumentException("Effect {$id} keyframes accept frame, duration, sourceFrame and position.");
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
          'payload' => ['sourceFrame' => $source, 'columns' => $sheet['columns'],
            'frameWidth' => intdiv($size['width'], $sheet['columns']), 'frameHeight' => intdiv($size['height'], $sheet['rows']),
            'cells' => $cells, 'depth' => $depth]];
      }
      usort($keyframes, static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
      $end = -1;
      foreach ($keyframes as $frame) {
        if ($frame['frame'] <= $end) { throw new InvalidArgumentException("Effect {$id} image track has overlapping keyframes."); }
        $end = $frame['frame'] + $frame['duration'] - 1;
      }
      if (!array_any($keyframes, static fn(array $frame): bool => $frame['frame'] <= $rest && $rest < $frame['frame'] + $frame['duration'])) {
        throw new InvalidArgumentException("Effect {$id} image track has no authored rest frame.");
      }
      $normalized[] = ['id' => $track['id'], 'type' => 'image', 'keyframes' => $keyframes];
    }
    return new CompiledEffectTimeline($id, sha1(json_encode($data, JSON_THROW_ON_ERROR)), fps: $fps,
      playbackSegments: (new EffectTimelineCompiler())->compileTracks($normalized),
      defaults: ['lengthFrames' => $length, 'restFrame' => $rest,
        'playback' => ['loop' => $playback === 'loop', 'loopFrom' => $loopFrom]]);
  }
}
