<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\EffectScale;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use InvalidArgumentException;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStageFrame;

/** One cue lane owns command stages; authored effects keep their original cadence. */
final readonly class BattleCommandTimeline
{
  public const int FPS = 120;
  public CompiledEffectTimeline $timeline;
  /** Announcement is a visibility interval that overlaps summon source/cutscene phases. */
  public array $phases;
  /** @var array<string, int> Reduced-motion inspection frames on the command lane. */
  public array $restFrames;
  /** @var array<string, list<array>> Original rest art, even when pacing skips that frame. */
  public array $restSegments;
  public ?SummonCompiledCutscene $summon;
  /** Legacy target-stage access; source stages use the same lane-owned inspection methods below. */
  public ?CinematicStage $cinematicStage;
  /** @var array<string, CinematicStage> Each effect lane owns its own stage and authored clock. */
  private array $cinematicStages;
  /** @var array<string, list<array>> Unshifted authored segments for each stage. */
  private array $cinematicSegments;
  public ?CompiledEffectTimeline $terminalTarget;
  public array $terminalSegments;
  public ?EffectPlaybackTiming $terminalTiming;
  private ?EffectPlaybackTiming $sourceTiming;
  private ?EffectPlaybackTiming $targetTiming;

  /**
   * @param float $targetScale The action binding's size for the target effect's images
   *   ({@see \Ichiloto\Engine\Animations\EffectScale}); the source effect and terminal glyphs keep their authored size.
   */
  public function __construct(BattleTurnTimings $timings, ?CompiledEffectTimeline $source = null,
    ?CompiledEffectTimeline $target = null, ?CompiledEffectTimeline $terminalTarget = null,
    public bool $resultsOnly = false, bool $showAnnouncement = true, float $targetScale = EffectScale::DEFAULT)
  {
    $targetScale = EffectScale::readValue($targetScale, 'Battle target effect scale');
    if ($resultsOnly) {
      if (!is_finite($timings->statChanges) || $timings->statChanges < 0) {
        throw new InvalidArgumentException('Battle result duration must be finite and non-negative.');
      }
      $length = max(1, (int)ceil($timings->statChanges * self::FPS));
      $this->summon = $this->terminalTarget = $this->terminalTiming = null;
      $this->cinematicStage = null;
      $this->cinematicStages = $this->cinematicSegments = [];
      $this->sourceTiming = $this->targetTiming = null;
      $this->terminalSegments = [];
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
    $stages = $stageSegments = [];
    foreach (['source' => $source, 'target' => $target] as $lane => $effect) {
      if (!is_array($effect?->defaults['stage'] ?? null)) { continue; }
      $stages[$lane] = CinematicStage::fromArray($effect->defaults['stage'], $effect->defaults['lengthFrames'],
        $effect->defaults['restFrame'] ?? 0);
      $stageSegments[$lane] = $effect->playbackSegments;
    }
    $this->cinematicStages = $stages;
    $this->cinematicSegments = $stageSegments;
    $this->cinematicStage = $stages['target'] ?? null;
    $this->terminalTarget = $target?->hasTerminalContent ? null : $terminalTarget;
    $this->terminalSegments = array_map(
      static fn(array $segment): array => self::getAnchoredSegment($segment, $terminalTarget, 'target'),
      $this->terminalTarget?->playbackSegments ?? []);
    $sourceTiming = $this->sourceTiming = $source === null ? null
      : EffectPlaybackTiming::createForBattlePhase($source, $timings->actionAnimation);
    $targetTiming = $this->targetTiming = $target === null ? null
      : EffectPlaybackTiming::createForBattlePhase($target, $timings->effectAnimation);
    $this->terminalTiming = $this->terminalTarget === null ? null
      : EffectPlaybackTiming::createForBattlePhase($this->terminalTarget, $timings->effectAnimation);
    $phases = $cues = $segments = $restFrames = $restSegments = [];
    foreach (['advance' => $timings->stepForward, 'announce' => $timings->announcement,
      'source' => $sourceTiming?->durationSeconds ?? $timings->actionAnimation,
      ...($this->summon === null || $this->cinematicStage !== null ? [] : [
        'summon-in' => max(0, (int)($this->summon->transitionCache['in']['durationMs'] ?? 0)) / 1000,
        'summon-title' => trim(strval($this->summon->defaults['name'] ?? '')) === '' ? 0 : .8,
      ]),
      'target' => max($targetTiming?->durationSeconds ?? $timings->effectAnimation,
        $this->terminalTiming?->durationSeconds ?? 0),
      ...($this->summon === null || $this->cinematicStage !== null ? [] : [
        'summon-out' => max(0, (int)($this->summon->transitionCache['out']['durationMs'] ?? 0)) / 1000,
      ]),
      'reaction' => $timings->statChanges, 'return' => $timings->stepBack,
      'finish' => $timings->turnOver] as $phase => $seconds) {
      if (!is_finite($seconds) || $seconds < 0) {
        throw new InvalidArgumentException('Battle command durations must be finite and non-negative.');
      }
      $effect = match ($phase) { 'source' => $source, 'target' => $target, default => null };
      $clock = match ($phase) { 'source' => $sourceTiming, 'target' => $targetTiming, default => null };
      $length = $phase === 'announce' && !$showAnnouncement ? 0
        : max(1, $clock?->getFrameBoundary($clock->totalFrames, self::FPS) ?? (int)ceil($seconds * self::FPS));
      if ($phase === 'target' && $this->terminalTiming !== null) {
        $length = max($length, $this->terminalTiming->getFrameBoundary($this->terminalTiming->totalFrames, self::FPS));
      }
      $phases[$phase] = ['start' => $cursor, 'length' => $length];
      if ($length > 0) {
        $cues[] = ['id' => 'command-' . $phase, 'type' => 'commandPhase', 'frame' => $cursor,
          'payload' => ['phase' => $phase]];
      }
      if ($effect !== null && $clock !== null) {
        $rest = clamp((int)($effect->defaults['restFrame'] ?? $clock->totalFrames - 1), 0, $clock->totalFrames - 1);
        $restFrames[$phase] = $cursor + $clock->getFrameBoundary($rest, self::FPS);
        $restSegments[$phase] = [];
        foreach ($effect->playbackSegments as $segment) {
          $copy = self::getAnchoredSegment($segment, $effect, $phase, $phase === 'target' ? $targetScale : EffectScale::DEFAULT);
          $copy['startFrame'] = $cursor + $clock->getFrameBoundary($segment['startFrame'], self::FPS);
          $copy['endFrame'] = $cursor + $clock->getFrameBoundary($segment['endFrame'] + 1, self::FPS) - 1;
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
      // Staged actions and summons start as their callout appears. Reading time
      // owns visibility while each authored lane keeps its own clock.
      if ($phase !== 'announce' || ($this->summon === null && $stages === [])) { $cursor += $length; }
    }
    if (($this->summon !== null || $stages !== []) && $showAnnouncement) {
      $cues[] = ['id' => 'command-announcement-end', 'type' => 'commandAnnouncementEnd',
        'frame' => min($phases['announce']['start'] + $phases['announce']['length'], $phases['finish']['start']),
        'payload' => []];
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

  /** Inspection uses the same authored clock as stage images and combat cues. */
  public function getCinematicStageFrame(int $frame, bool $reducedMotion = false): ?CinematicStageFrame
  {
    $lane = $this->getActiveCinematicLane($frame);
    return $lane === null ? null : $this->cinematicStages[$lane]->getFrame(
      $this->getAuthoredFrameAtCommandFrame($lane, $frame), $reducedMotion);
  }

  /** @return list<array> The active stage's original segments, using the same authored clock as its frame. */
  public function getCinematicStageSegments(int $frame): array
  {
    $lane = $this->getActiveCinematicLane($frame);
    return $lane === null ? [] : $this->cinematicSegments[$lane];
  }

  public function hasCinematicStage(string $lane): bool
  {
    return isset($this->cinematicStages[$lane]);
  }

  public function hasCinematicStages(): bool
  {
    return $this->cinematicStages !== [];
  }

  private function getActiveCinematicLane(int $frame): ?string
  {
    foreach ($this->cinematicStages as $lane => $stage) {
      if ($this->getAuthoredFrameAtCommandFrame($lane, $frame) !== null) { return $lane; }
    }
    return null;
  }

  /** Boundaries never preview a cue early; fast authored frames may share a command tick. */
  public function getCommandFrameForAuthoredFrame(string $lane, int $frame): ?int
  {
    $clock = $this->getTimingForAuthoredLane($lane);
    if ($clock === null || $frame < 0 || $frame >= $clock->totalFrames) { return null; }
    $offset = $clock->getFrameBoundary($frame, self::FPS);
    if ($offset >= $clock->getFrameBoundary($clock->totalFrames, self::FPS)) { return null; }
    return $this->phases[$lane === 'terminal-target' ? 'target' : $lane]['start'] + $offset;
  }

  /** Null means this authored lane is inactive, even if paired fallback art is held. */
  public function getAuthoredFrameAtCommandFrame(string $lane, int $frame): ?int
  {
    $clock = $this->getTimingForAuthoredLane($lane);
    if ($clock === null) { return null; }
    $phase = $this->phases[$lane === 'terminal-target' ? 'target' : $lane];
    $offset = $frame - $phase['start'];
    if ($offset < 0 || $offset >= $phase['length']
      || $offset >= $clock->getFrameBoundary($clock->totalFrames, self::FPS)) { return null; }
    return min($clock->totalFrames - 1, $clock->getFrameCountAt($offset / self::FPS));
  }

  /** @return array{source: ?int, target: ?int, 'terminal-target': ?int} */
  public function getAuthoredFramesAtCommandFrame(int $frame): array
  {
    return ['source' => $this->getAuthoredFrameAtCommandFrame('source', $frame),
      'target' => $this->getAuthoredFrameAtCommandFrame('target', $frame),
      'terminal-target' => $this->getAuthoredFrameAtCommandFrame('terminal-target', $frame)];
  }

  private function getTimingForAuthoredLane(string $lane): ?EffectPlaybackTiming
  {
    return match ($lane) {
      'source' => $this->sourceTiming,
      'target' => $this->targetTiming,
      'terminal-target' => $this->terminalTiming,
      default => throw new InvalidArgumentException('An authored battle lane must be source, target or terminal-target.'),
    };
  }

  /** A scaled lane stamps its image commands; glyphs and text keep their cells, which cannot resize without distortion. */
  private static function getAnchoredSegment(array $segment, CompiledEffectTimeline $effect, string $phase,
    float $scale = EffectScale::DEFAULT): array
  {
    foreach ($segment['drawCommands'] as &$command) {
      $command['trackId'] = $phase . '-' . ($command['trackId'] ?? 'track');
      $command['payload']['anchor'] ??= $effect instanceof SummonCompiledCutscene && $segment['layer'] !== 'image'
        ? 'legacy-screen' : ($phase === 'source' ? 'caster' : 'target');
      if ($segment['layer'] === 'image' && !EffectScale::playsAuthoredSize($scale)) {
        $command['payload']['scale'] = $scale;
      }
    }
    unset($command);
    return $segment;
  }
}
