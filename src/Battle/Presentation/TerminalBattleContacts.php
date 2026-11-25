<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use InvalidArgumentException;

/** Fixed authored spacing for Terminal ground effects on the selected recipients. */
final readonly class TerminalBattleContacts
{
  public const string FIELD = 'terminalTargetContacts';

  private array $segments;
  private int $endFrame;
  private int $intervalFrames;
  private array $stillCommands;
  public int $captureFrame;

  public function __construct(CompiledEffectTimeline $effect, private EffectPlaybackTiming $timing)
  {
    $data = $effect->defaults[self::FIELD];
    $this->captureFrame = $data['captureFrame'];
    $this->intervalFrames = $data['intervalFrames'];
    $this->segments = array_values(array_filter($effect->playbackSegments, static fn(array $segment): bool =>
      in_array($segment['drawCommands'][0]['trackId'], $data['tracks'], true)));
    $this->endFrame = max(array_column($this->segments, 'endFrame')) + 1;
    $stills = $ranks = [];
    $restFrame = $effect->defaults['restFrame'];
    foreach ($this->segments as $segment) {
      $command = $segment['drawCommands'][0];
      if (!$command['visible']) { continue; }
      $track = $command['trackId'];
      $frame = clamp($restFrame, $segment['startFrame'], $segment['endFrame']);
      $rank = [abs($frame - $restFrame), $frame];
      if (!isset($ranks[$track]) || $rank < $ranks[$track]) {
        $stills[$track] = $command;
        $ranks[$track] = $rank;
      }
    }
    $this->stillCommands = $stills;
  }

  /** Shared by the source validator and authoring track pickers. */
  public static function canUseTrack(mixed $track): bool
  {
    if (!is_array($track) || !is_string($track['id'] ?? null) || !is_array($track['keyframes'] ?? null)
      || $track['keyframes'] === []) { return false; }
    return match ($track['type'] ?? null) {
      'glyph-lane' => ($track['presentation'] ?? 'terminal') === 'terminal',
      'glyph' => ($track['presentation'] ?? null) === 'terminal' && array_all($track['keyframes'], static function (mixed $key) use ($track): bool {
        if (!is_array($key) || !is_array($key['payload'] ?? [])) { return false; }
        $payload = $key['payload'] ?? [];
        return ($track['anchor'] ?? $payload['anchor'] ?? 'target') === 'target'
          && ($payload['attachment'] ?? $payload['legacyPosition'] ?? 'feet') === 'feet'
          && !($payload['clearBeforeDraw'] ?? false);
      }),
      default => false,
    };
  }

  /** @param list<array> $tracks Validated Terminal tracks with normalized anchors. */
  public static function readSource(mixed $data, array $tracks, int $length, ?int $captureFrame): array
  {
    if (!is_array($data) || array_diff(array_keys($data), ['intervalFrames', 'tracks']) !== []
      || !is_int($data['intervalFrames'] ?? null) || $data['intervalFrames'] < 1 || $data['intervalFrames'] >= $length
      || !is_array($data['tracks'] ?? null) || !array_is_list($data['tracks']) || $data['tracks'] === []
      || array_any($data['tracks'], static fn(mixed $track): bool => !is_string($track))
      || count(array_unique($data['tracks'])) !== count($data['tracks'])) {
      throw new InvalidArgumentException('Terminal target contacts require a positive frame interval and distinct Terminal track IDs.');
    }
    $first = $length;
    foreach ($data['tracks'] as $id) {
      $track = array_find($tracks, static fn(array $track): bool => ($track['id'] ?? null) === $id);
      if (!self::canUseTrack($track)) {
        throw new InvalidArgumentException('Terminal target contacts require ground-anchored Terminal glyphs or glyph lanes without field clearing.');
      }
      $first = min($first, min(array_column($track['keyframes'], 'frame')));
    }
    if ($captureFrame !== null && $captureFrame > $first) {
      throw new InvalidArgumentException('Terminal ground points must be captured by the first contact sample.');
    }
    return $data + ['captureFrame' => $captureFrame ?? $first];
  }

  public function getCaptureTick(): int
  {
    return $this->timing->getFrameBoundary($this->captureFrame, BattleCommandTimeline::FPS);
  }

  /** An extra recipient can extend the visual phase without moving the command's impact. */
  public function getEndTick(int $targetCount): int
  {
    return $this->timing->getFrameBoundary($this->endFrame + max(0, $targetCount - 1) * $this->intervalFrames,
      BattleCommandTimeline::FPS);
  }

  /** @return array<int, int> Selected identity => delay in authored frames. */
  public function getRecipientDelays(TerminalBattleLaneSnapshot $snapshot): array
  {
    $delays = [];
    foreach ($snapshot->getTargetsByGroundDistance() as $rank => $id) {
      $delays[$id] = $rank * $this->intervalFrames;
    }
    return $delays;
  }

  /** Read-only sampling. Rendering never progresses time or dispatches an effect. */
  public function sample(int $tick, array $delays, bool $reducedMotion): array
  {
    $at = $this->timing->getFrameCountAt($tick / BattleCommandTimeline::FPS);
    $segments = [];
    foreach ($delays as $id => $delay) {
      $local = $at - $delay;
      foreach ($this->segments as $segment) {
        $command = $segment['drawCommands'][0];
        if ($segment['startFrame'] > $local || $segment['endFrame'] < $local || !$command['visible']) { continue; }
        if ($reducedMotion) { $command = $this->stillCommands[$command['trackId']]; }
        $command['terminalContactSubject'] = $id;
        $segment['drawCommands'] = [$command];
        $segments[] = $segment;
      }
    }
    return $segments;
  }
}
