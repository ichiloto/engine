<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use InvalidArgumentException;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;

/** One cue lane owns command stages; authored effects keep their original cadence. */
final readonly class BattleCommandTimeline
{
  public const int FPS = 120;
  public CompiledEffectTimeline $timeline;
  public array $phases;
  /** @var array<string, int> Reduced-motion inspection frames on the command lane. */
  public array $restFrames;
  /** @var array<string, list<array>> Original rest art, even when pacing skips that frame. */
  public array $restSegments;
  public ?SummonCompiledCutscene $summon;
  public ?CompiledEffectTimeline $terminalTarget;
  public ?EffectPlaybackTiming $terminalTiming;

  public function __construct(BattleTurnTimings $timings, ?CompiledEffectTimeline $source = null,
    ?CompiledEffectTimeline $target = null, ?CompiledEffectTimeline $terminalTarget = null,
    public bool $resultsOnly = false)
  {
    if ($resultsOnly) {
      if (!is_finite($timings->statChanges) || $timings->statChanges < 0) {
        throw new InvalidArgumentException('Battle result duration must be finite and non-negative.');
      }
      $length = max(1, (int)ceil($timings->statChanges * self::FPS));
      $this->summon = $this->terminalTarget = $this->terminalTiming = null;
      $this->restFrames = $this->restSegments = [];
      $this->phases = ['reaction' => ['start' => 0, 'length' => $length],
        'return' => ['start' => $length, 'length' => 1], 'finish' => ['start' => $length + 1, 'length' => 1]];
      $this->timeline = new CompiledEffectTimeline('battle-result', '', fps: self::FPS, cueSchedule: [
        ['id' => 'result-impact', 'type' => 'commandImpact', 'frame' => 0, 'payload' => []],
        ...array_map(static fn(string $phase, array $timing): array => [
          'id' => 'result-' . $phase, 'type' => 'commandPhase', 'frame' => $timing['start'],
          'payload' => ['phase' => $phase]], array_keys($this->phases), array_values($this->phases)),
      ], defaults: ['lengthFrames' => $length + 2, 'restFrame' => 0]);
      return;
    }
    $cursor = 0;
    $this->summon = $target instanceof SummonCompiledCutscene ? $target : null;
    $this->terminalTarget = array_any($target?->playbackSegments ?? [],
      static fn(array $segment): bool => EffectPresentation::TERMINAL->acceptsSegment($segment)
        && in_array($segment['layer'], ['glyph', 'text'], true)) ? null : $terminalTarget;
    $sourceTiming = $source === null ? null : new EffectPlaybackTiming($source);
    $targetTiming = $target === null ? null : new EffectPlaybackTiming($target);
    $this->terminalTiming = $this->terminalTarget === null ? null : new EffectPlaybackTiming($this->terminalTarget);
    $phases = $cues = $segments = $restFrames = $restSegments = [];
    foreach (['advance' => $timings->stepForward, 'announce' => $timings->announcement,
      'source' => $sourceTiming?->durationSeconds ?? $timings->actionAnimation,
      ...($this->summon === null ? [] : [
        'summon-in' => max(0, (int)($this->summon->transitionCache['in']['durationMs'] ?? 0)) / 1000,
        'summon-title' => trim(strval($this->summon->defaults['name'] ?? '')) === '' ? 0 : .8,
      ]),
      'target' => max($targetTiming?->durationSeconds ?? $timings->effectAnimation,
        $this->terminalTiming?->durationSeconds ?? 0),
      ...($this->summon === null ? [] : [
        'summon-out' => max(0, (int)($this->summon->transitionCache['out']['durationMs'] ?? 0)) / 1000,
      ]),
      'reaction' => $timings->statChanges, 'return' => $timings->stepBack,
      'finish' => $timings->turnOver] as $phase => $seconds) {
      if (!is_finite($seconds) || $seconds < 0) {
        throw new InvalidArgumentException('Battle command durations must be finite and non-negative.');
      }
      $effect = match ($phase) { 'source' => $source, 'target' => $target, default => null };
      $clock = match ($phase) { 'source' => $sourceTiming, 'target' => $targetTiming, default => null };
      $length = max(1, $clock?->getFrameBoundary($clock->totalFrames, self::FPS) ?? (int)ceil($seconds * self::FPS));
      if ($phase === 'target' && $this->terminalTiming !== null) {
        $length = max($length, $this->terminalTiming->getFrameBoundary($this->terminalTiming->totalFrames, self::FPS));
      }
      $phases[$phase] = ['start' => $cursor, 'length' => $length];
      $cues[] = ['id' => 'command-' . $phase, 'type' => 'commandPhase', 'frame' => $cursor,
        'payload' => ['phase' => $phase]];
      if ($effect !== null && $clock !== null) {
        $rest = clamp((int)($effect->defaults['restFrame'] ?? $clock->totalFrames - 1), 0, $clock->totalFrames - 1);
        $restFrames[$phase] = $cursor + $clock->getFrameBoundary($rest, self::FPS);
        $restSegments[$phase] = [];
        foreach ($effect->playbackSegments as $segment) {
          $copy = $segment;
          $copy['startFrame'] = $cursor + $clock->getFrameBoundary($segment['startFrame'], self::FPS);
          $copy['endFrame'] = $cursor + $clock->getFrameBoundary($segment['endFrame'] + 1, self::FPS) - 1;
          foreach ($copy['drawCommands'] as &$command) {
            $command['trackId'] = $phase . '-' . ($command['trackId'] ?? 'track');
            $command['payload']['anchor'] ??= $effect instanceof \Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene
              ? 'legacy-screen' : ($phase === 'source' ? 'caster' : 'target');
          }
          unset($command);
          if ($segment['startFrame'] <= $rest && $rest <= $segment['endFrame']) {
            $restSegments[$phase][] = [...$copy, 'startFrame' => $cursor, 'endFrame' => $cursor + $length - 1];
          }
          if ($copy['startFrame'] <= $copy['endFrame']) { $segments[] = $copy; }
        }
        foreach ($effect->cueSchedule as $cue) {
          $sourceFrame = $cue['frame'];
          $start = $clock->getFrameBoundary($sourceFrame, self::FPS);
          $cue['frame'] = $cursor + $start;
          $cue['payload']['anchor'] ??= $phase === 'source' ? 'caster' : 'target';
          if (isset($cue['payload']['durationFrames'])) {
            $cue['payload']['durationFrames'] = max(1,
              $clock->getFrameBoundary($sourceFrame + $cue['payload']['durationFrames'], self::FPS) - $start);
          }
          if (in_array(strtolower($cue['type']), ['flash', 'shake'], true)) {
            $duration = $cue['payload']['durationFrames'] ?? max(1,
              $clock->getFrameBoundary($sourceFrame + ($cue['payload']['duration'] ?? 1), self::FPS) - $start);
            $segments[] = ['startFrame' => $cue['frame'], 'endFrame' => min($cursor + $length - 1, $cue['frame'] + $duration - 1),
              'layer' => strtolower($cue['type']), 'drawCommands' => [['trackId' => $phase . '-cue-' . $cue['id'],
                'payload' => $cue['payload']]]];
          }
          $cues[] = $cue;
        }
      }
      $cursor += $length;
    }
    // Summons retain their explicit cue/frame/end policy; ordinary effects may
    // author an impact cue. No cue means resolution at the target stage's end.
    $timing = $target?->defaults['effectTiming'] ?? null;
    $impact = $phases['target']['start'] + $phases['target']['length'];
    if (is_array($timing) && in_array($timing['mode'] ?? 'end', ['frame', 'explicit_frame'], true)) {
      $impact = min($impact, $phases['target']['start'] + $targetTiming->getFrameBoundary((int)($timing['frame'] ?? 0), self::FPS));
    } elseif (!is_array($timing) || ($timing['mode'] ?? 'end') === 'cue') {
      $cueId = $timing['cueId'] ?? null;
      $cue = array_find($target?->cueSchedule ?? [], static fn(array $cue): bool => $cueId === null
        ? $cue['type'] === 'applyEffect' : $cue['id'] === $cueId);
      if ($cue !== null) {
        $impact = min($impact, $phases['target']['start'] + $targetTiming->getFrameBoundary($cue['frame'], self::FPS));
      }
    }
    $cues[] = ['id' => 'command-impact', 'type' => 'commandImpact', 'frame' => $impact, 'payload' => []];
    usort($cues, static fn(array $a, array $b): int => [$a['frame'], $a['type'] === 'commandImpact' ? 0 : 1]
      <=> [$b['frame'], $b['type'] === 'commandImpact' ? 0 : 1]);
    $this->phases = $phases;
    $this->restFrames = $restFrames;
    $this->restSegments = $restSegments;
    $this->timeline = new CompiledEffectTimeline('battle-command', '', fps: self::FPS,
      playbackSegments: $segments, cueSchedule: $cues,
      defaults: ['lengthFrames' => $cursor, 'restFrame' => $cursor - 1]);
  }
}
