<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\EffectScale;
use Ichiloto\Engine\Animations\SourceEffectAnchor;
use Ichiloto\Engine\Animations\Timelines\BattleEffectRangeTiming;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectAnchorLifetime;
use Ichiloto\Engine\Animations\Timelines\EffectFlightRoute;
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
  /** Command-clock frame for the action pose's authored synchronization entry. */
  public ?int $actionPoseAnchorFrame;
  /** Exclusive completion boundary for the selected impact-anchored action pose. */
  public ?int $actionPoseEndFrame;
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
  private ?BattleEffectRangeTiming $rangeTiming;

  public function requiresGraphicalAnchorCapture(): bool
  {
    return array_any($this->timeline->cueSchedule, static fn(array $cue): bool =>
      $cue['type'] === 'commandCapturePosePoint' || $cue['type'] === 'commandCaptureTargetGroup');
  }

  /**
   * @param float $targetScale The action binding's size for the target effect's images
   *   ({@see \Ichiloto\Engine\Animations\EffectScale}); the source effect and terminal glyphs keep their authored size.
   */
  public function __construct(BattleTurnTimings $timings, ?CompiledEffectTimeline $source = null,
    ?CompiledEffectTimeline $target = null, ?CompiledEffectTimeline $terminalTarget = null,
    public bool $resultsOnly = false, bool $showAnnouncement = true, float $targetScale = EffectScale::DEFAULT,
    public SourceEffectAnchor $sourceEffectAnchor = SourceEffectAnchor::SOURCE, ?BattlerPose $actionPose = null,
    public bool $movementPoses = false, public ?BattleTravelPlan $travel = null,
    ?BattlerPose $movePose = null, ?BattlerPose $returnPose = null, ?float $projectedRange = null,
    public ?BattlerPose $actionPoseOverride = null)
  {
    $targetScale = EffectScale::readValue($targetScale, 'Battle target effect scale');
    if ($actionPoseOverride !== null && $actionPoseOverride->impactFrame === null) {
      throw new InvalidArgumentException('A bound action pose requires an impact entry.');
    }
    $actionPose = $actionPoseOverride ?? $actionPose;
    if (isset($source->defaults['rangeTiming'])) {
      throw new InvalidArgumentException('Battle range timing belongs to the target effect lane.');
    }
    $rangeTiming = !$resultsOnly && $projectedRange !== null && isset($target->defaults['rangeTiming'])
      ? BattleEffectRangeTiming::fromArray($target->defaults['rangeTiming'], $target->defaults['lengthFrames'],
        $target->defaults['rangeTimingFixedFrames'])
      : null;
    $this->rangeTiming = $rangeTiming !== null && $projectedRange >= $rangeTiming->minDistance ? $rangeTiming : null;
    if ($resultsOnly) {
      $this->actionPoseAnchorFrame = null;
      $this->actionPoseEndFrame = null;
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
    if ($sourceEffectAnchor === SourceEffectAnchor::TARGET && isset($stages['source'])) {
      throw new InvalidArgumentException('A target-anchored source effect must use battlefield tracks, not a source cinematic stage.');
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
    $detachments = $targetGroupCaptures = [];
    $targetImpactEnd = 0;
    $poseAnchor = null;
    $poseEnd = null;
    $transition = static fn(?BattlerPose $pose, BattlePoseSequence $sequence, string $phase): array =>
      $movementPoses && $pose !== null && $pose->getSequenceSeconds($sequence) > 0
        ? [$phase => $pose->getSequenceSeconds($sequence)] : [];
    foreach ([...$transition($movePose, BattlePoseSequence::ENTRY, 'enter-advance'),
      'advance' => $travel === null ? $timings->stepForward : ($travel->isPossible ? $travel->advanceSeconds : 0),
      ...$transition($movePose, BattlePoseSequence::EXIT, 'exit-advance'), 'announce' => $timings->announcement,
      'source' => $sourceEffectAnchor === SourceEffectAnchor::TARGET ? $timings->actionAnimation
        : ($sourceTiming?->durationSeconds ?? $timings->actionAnimation),
      ...($this->summon === null || $this->cinematicStage !== null ? [] : [
        'summon-in' => max(0, (int)($this->summon->transitionCache['in']['durationMs'] ?? 0)) / 1000,
        'summon-title' => trim(strval($this->summon->defaults['name'] ?? '')) === '' ? 0 : .8,
      ]),
      'target' => max($targetTiming?->durationSeconds ?? $timings->effectAnimation,
        $this->terminalTiming?->durationSeconds ?? 0),
      ...($this->summon === null || $this->cinematicStage !== null ? [] : [
        'summon-out' => max(0, (int)($this->summon->transitionCache['out']['durationMs'] ?? 0)) / 1000,
      ]),
      'reaction' => $timings->statChanges, ...($movementPoses || $travel !== null ? ['recovery' => 0] : []),
      ...$transition($returnPose, BattlePoseSequence::ENTRY, 'enter-return'),
      'return' => $travel === null ? $timings->stepBack : ($travel->isPossible ? $travel->returnSeconds : 0),
      ...$transition($returnPose, BattlePoseSequence::EXIT, 'exit-return'),
      'finish' => $timings->turnOver] as $phase => $seconds) {
      if (!is_finite($seconds) || $seconds < 0) {
        throw new InvalidArgumentException('Battle command durations must be finite and non-negative.');
      }
      $clock = match ($phase) {
        'source' => $sourceEffectAnchor === SourceEffectAnchor::TARGET ? null : $sourceTiming,
        'target' => $targetTiming, default => null };
      $length = $phase === 'announce' && !$showAnnouncement ? 0
        : max(1, $clock?->getFrameBoundary($clock->totalFrames, self::FPS) ?? (int)ceil($seconds * self::FPS));
      if ($phase === 'target' && $this->terminalTiming !== null) {
        $length = max($length, $this->terminalTiming->getFrameBoundary($this->terminalTiming->totalFrames, self::FPS));
      }
      if ($phase === 'target') { $targetImpactEnd = $cursor + $length; }
      if ($phase === 'target' && $sourceEffectAnchor === SourceEffectAnchor::TARGET && $sourceTiming !== null) {
        $length = max($length, $sourceTiming->getFrameBoundary($sourceTiming->totalFrames, self::FPS));
      }
      if ($phase === 'target') {
        $syncFrame = $target?->defaults['poseSyncFrame'] ?? null;
        if ($syncFrame !== null && (!is_int($syncFrame) || $syncFrame < 0 || $syncFrame >= $targetTiming->totalFrames)) {
          throw new InvalidArgumentException('Battle action pose synchronization requires a valid target effect frame.');
        }
        $poseAnchor = $cursor + ($syncFrame === null ? 0 : $targetTiming->getFrameBoundary($syncFrame, self::FPS));
        if ($actionPose?->impactFrame !== null) {
          $poseEnd = $poseAnchor + (int)ceil($actionPose->getRecoverySeconds() * self::FPS);
          if ($this->rangeTiming !== null) {
            $authoredEnd = ($poseEnd - $cursor) / self::FPS / $targetTiming->secondsPerFrame;
            $poseEnd = $cursor + $targetTiming->getFrameBoundary(
              $this->rangeTiming->getPresentationFrame($authoredEnd), self::FPS);
          }
        }
      }
      // An authored walk home starts after anchored action recovery, preserving all impact/effect cues.
      if ($phase === 'recovery') { $length = max(0, ($poseEnd ?? $cursor) - $cursor); }
      // Without movement artwork, retain the existing overlapping return/completion timing.
      if ($phase === 'finish' && $poseEnd !== null) { $length = max($length, $poseEnd - $cursor); }
      $phases[$phase] = ['start' => $cursor, 'length' => $length];
      if ($length > 0) {
        $cues[] = ['id' => 'command-' . $phase, 'type' => 'commandPhase', 'frame' => $cursor,
          'payload' => ['phase' => $phase]];
      }
      $effects = match ($phase) {
        'source' => $sourceEffectAnchor === SourceEffectAnchor::SOURCE ? [['source', $source, $sourceTiming]] : [],
        'target' => [...($sourceEffectAnchor === SourceEffectAnchor::TARGET ? [['source', $source, $sourceTiming]] : []),
          ['target', $target, $targetTiming]],
        default => [],
      };
      foreach ($effects as [$lane, $effect, $clock]) {
        if ($effect === null || $clock === null) { continue; }
        $rest = clamp((int)($effect->defaults['restFrame'] ?? $clock->totalFrames - 1), 0, $clock->totalFrames - 1);
        $range = $lane === 'target' ? $this->rangeTiming : null;
        $restFrames[$lane] = $cursor + $clock->getFrameBoundary($range?->getPresentationFrame($rest) ?? $rest, self::FPS);
        $restSegments[$phase] ??= [];
        foreach ($effect->playbackSegments as $segment) {
          $copy = self::getAnchoredSegment($segment, $effect, $lane, $lane === 'target' ? $targetScale : EffectScale::DEFAULT);
          $visualClock = EffectPresentation::TERMINAL->acceptsSegment($segment) ? null : $range;
          $boundary = static fn(int $frame): int => $clock->getFrameBoundary(
            $visualClock?->getPresentationFrame($frame) ?? $frame, self::FPS);
          foreach ($copy['drawCommands'] as &$command) {
            if (($command['payload']['anchorLifetime'] ?? null)
              === EffectAnchorLifetime::EFFECT_START->value) {
              $command['payload']['capturedTargetGroup'] = $lane;
              $targetGroupCaptures[$lane] ??= ['id' => 'capture-target-group-' . $lane,
                'type' => 'commandCaptureTargetGroup', 'frame' => $cursor, 'payload' => ['lane' => $lane]];
            }
            if (!isset($command['payload']['detachFrame'])) { continue; }
            $detach = $cursor + $boundary($command['payload']['detachFrame']);
            $command['payload']['detachFrame'] = $detach;
            $flight = $command['payload']['flight'] ?? null;
            if ($flight !== null) {
              foreach (['arrivalFrame', 'holdFrame'] as $field) {
                if (!isset($flight[$field])) { continue; }
                $command['payload']['flight'][$field] = $cursor + $boundary($flight[$field]);
              }
            }
            if ($copy['layer'] === 'trail') {
              $command['payload']['fadeEndFrame'] = $cursor + $boundary($command['payload']['fadeEndFrame']);
              $command['payload']['restSampleFrame'] = $restFrames[$lane];
            }
            $trackId = $command['trackId'];
            $detachments[$trackId] ??= ['id' => 'capture-' . $trackId, 'type' => 'commandCapturePosePoint', 'frame' => $detach,
              'payload' => ['trackId' => $trackId, 'anchor' => $command['payload']['anchor'],
                'attachmentPoint' => $command['payload']['attachmentPoint'],
                'route' => $flight === null ? null : ($flight['route'] ?? EffectFlightRoute::WAYPOINTS->value),
                'curve' => $visualClock !== null ? ($flight['farCurve'] ?? $flight['curve'] ?? null) : ($flight['curve'] ?? null)]];
          }
          unset($command);
          $copy['startFrame'] = $cursor + $boundary($segment['startFrame']);
          $copy['endFrame'] = $cursor + $boundary($segment['endFrame'] + 1) - 1;
          if ($segment['startFrame'] <= $rest && $rest <= $segment['endFrame']) {
            $restSegments[$phase][] = [...$copy, 'startFrame' => $cursor, 'endFrame' => $cursor + $length - 1];
          }
          if ($copy['startFrame'] <= $copy['endFrame']) { $segments[] = $copy; }
        }
        foreach ($effect->cueSchedule as $cue) {
          $sourceFrame = $cue['frame'];
          $start = $clock->getFrameBoundary($sourceFrame, self::FPS);
          $cue['frame'] = $cursor + $start;
          $cue['payload']['anchor'] ??= $lane === 'source' ? 'caster' : 'target';
          if (isset($cue['payload']['durationFrames'])) {
            $cue['payload']['durationFrames'] = max(1,
              $clock->getFrameBoundary($sourceFrame + $cue['payload']['durationFrames'], self::FPS) - $start);
          }
          if (in_array(strtolower($cue['type']), ['flash', 'shake'], true)) {
            $duration = $cue['payload']['durationFrames'] ?? max(1,
              $clock->getFrameBoundary($sourceFrame + ($cue['payload']['duration'] ?? 1), self::FPS) - $start);
            $segments[] = ['startFrame' => $cue['frame'], 'endFrame' => min($cursor + $length - 1, $cue['frame'] + $duration - 1),
              'layer' => strtolower($cue['type']), 'drawCommands' => [['trackId' => $lane . '-cue-' . $cue['id'],
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
    $impact = $targetImpactEnd;
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
    $cues = [...$cues, ...array_values($detachments), ...array_values($targetGroupCaptures)];
    $order = static fn(array $cue): int => match ($cue['type']) {
      'commandCapturePosePoint', 'commandCaptureTargetGroup' => 0, 'commandImpact' => 1, default => 2,
    };
    usort($cues, static fn(array $a, array $b): int => [$a['frame'], $order($a)] <=> [$b['frame'], $order($b)]);
    $this->actionPoseAnchorFrame = $poseAnchor;
    $this->actionPoseEndFrame = $poseEnd;
    $this->phases = $phases;
    $this->restFrames = $restFrames;
    $this->restSegments = $restSegments;
    $this->timeline = new CompiledEffectTimeline('battle-command', '', fps: self::FPS,
      playbackSegments: $segments, cueSchedule: $cues,
      defaults: ['lengthFrames' => $cursor, 'restFrame' => $cursor - 1]);
  }

  /** The caster uses the same presentation clock as its detached flight tracks. */
  public function getActionPoseClockFrame(int $frame): float
  {
    if ($this->rangeTiming === null) { return $frame; }
    $start = $this->phases['target']['start'];
    $framesPerSecond = $this->targetTiming->fps * $this->targetTiming->effectiveSpeed;
    $authored = $this->rangeTiming->getAuthoredFrame(($frame - $start) * $framesPerSecond / self::FPS);
    return $start + $authored * self::FPS / $framesPerSecond;
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
    $sample = $lane === 'target' ? ($this->rangeTiming?->getPresentationFrame($frame) ?? $frame) : $frame;
    $offset = $clock->getFrameBoundary($sample, self::FPS);
    if ($offset >= $clock->getFrameBoundary($clock->totalFrames, self::FPS)) { return null; }
    return $this->phases[$this->getPhaseForAuthoredLane($lane)]['start'] + $offset;
  }

  /** Null means this authored lane is inactive, even if paired fallback art is held. */
  public function getAuthoredFrameAtCommandFrame(string $lane, int $frame): ?int
  {
    $clock = $this->getTimingForAuthoredLane($lane);
    if ($clock === null) { return null; }
    $phase = $this->phases[$this->getPhaseForAuthoredLane($lane)];
    $offset = $frame - $phase['start'];
    if ($offset < 0 || $offset >= $phase['length']
      || $offset >= $clock->getFrameBoundary($clock->totalFrames, self::FPS)) { return null; }
    $seconds = $offset / self::FPS;
    if ($lane === 'target' && $this->rangeTiming !== null) {
      $seconds = $this->rangeTiming->getAuthoredFrame($seconds / $clock->secondsPerFrame) * $clock->secondsPerFrame;
    }
    return min($clock->totalFrames - 1, $clock->getFrameCountAt($seconds));
  }

  /** @return array{source: ?int, target: ?int, 'terminal-target': ?int} */
  public function getAuthoredFramesAtCommandFrame(int $frame): array
  {
    return ['source' => $this->getAuthoredFrameAtCommandFrame('source', $frame),
      'target' => $this->getAuthoredFrameAtCommandFrame('target', $frame),
      'terminal-target' => $this->getAuthoredFrameAtCommandFrame('terminal-target', $frame)];
  }

  private function getPhaseForAuthoredLane(string $lane): string
  {
    return $lane === 'terminal-target' || ($lane === 'source' && $this->sourceEffectAnchor === SourceEffectAnchor::TARGET)
      ? 'target' : $lane;
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
