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
use Ichiloto\Engine\Animations\Timelines\EffectImpactTimingMode;
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
  /** Target-clock poses begin with the effect instead of during the announcement. */
  public ?int $actionPoseStartFrame;
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
  public bool $usesArenaImpact;
  /** Frame interval for hit presentation when the selected straight flight exits horizontally. */
  public int $fallbackHitSpacing;
  public int $impactFrame;
  public ?string $fallbackHitTrack;
  public ?BattleTargetContactSequence $targetContactSequence;
  /** @var list<int> Authored per-hit feedback on the command clock. */
  public array $hitFeedbackFrames;
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
  /** @var array{track: string, release: int, arrival: int, speed: float}|null */
  public ?array $flightTiming;

  public function requiresGraphicalAnchorCapture(): bool
  {
    return array_any($this->timeline->cueSchedule, static fn(array $cue): bool =>
      $cue['type'] === 'commandCapturePosePoint' || in_array($cue['type'], ['commandCaptureTargetGroup', 'commandCaptureTargetContacts', 'commandCaptureTargetSnapshot'], true));
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
    public ?BattlerPose $actionPoseOverride = null,
    public ?\Ichiloto\Engine\Animations\FlashStepTiming $flashStep = null, int $targetCount = 1)
  {
    $targetScale = EffectScale::readValue($targetScale, 'Battle target effect scale');
    if ($actionPoseOverride !== null && $actionPoseOverride->impactFrame === null) {
      throw new InvalidArgumentException('A bound action pose requires an impact entry.');
    }
    if ($flashStep !== null && ($resultsOnly || !$travel?->isPossible || !$flashStep->supportsPose($actionPoseOverride)
      || ($source !== null && $sourceEffectAnchor !== SourceEffectAnchor::TARGET))) {
      throw new InvalidArgumentException('Flash step requires a resolved route, action transit cells and a planted first frame.');
    }
    $actionPose = $actionPoseOverride ?? $actionPose;
    $this->fallbackHitTrack = $fallbackHitTrack = !$resultsOnly && isset($target->defaults['fallbackHitTrack'])
      ? 'target-' . $target->defaults['fallbackHitTrack'] : null;
    $targetContacts = null;
    if (isset($source->defaults['hitFeedbackFrames'])) {
      throw new InvalidArgumentException('Recorded hit feedback belongs to the target effect lane.');
    }
    if (isset($source->defaults['targetCaptureFrame'])) {
      throw new InvalidArgumentException('Target capture belongs to the target effect lane.');
    }
    if (isset($source->defaults['targetContactSequence'])) {
      throw new InvalidArgumentException('Target contact sequencing belongs to the target effect lane.');
    }
    $this->usesArenaImpact = !$resultsOnly && ($target?->defaults['effectTiming']['mode'] ?? null) === EffectImpactTimingMode::ARENA->value;
    if (($source?->defaults['effectTiming']['mode'] ?? null) === EffectImpactTimingMode::ARENA->value) {
      throw new InvalidArgumentException('Arena impact belongs to the target effect lane.');
    }
    $targetPoseClock = ($target?->defaults['poseClock'] ?? null)
      === \Ichiloto\Engine\Animations\Timelines\EffectPoseClock::TARGET->value;
    if (isset($source->defaults['poseClock'])) {
      throw new InvalidArgumentException('A target pose clock belongs to the target effect lane.');
    }
    if (isset($source->defaults['rangeTiming']) || isset($source->defaults['flightTiming'])) {
      throw new InvalidArgumentException('Battle range and flight timing belong to the target effect lane.');
    }
    $rangeTiming = !$resultsOnly && $projectedRange !== null && isset($target->defaults['rangeTiming'])
      ? BattleEffectRangeTiming::fromArray($target->defaults['rangeTiming'], $target->defaults['lengthFrames'],
        $target->defaults['rangeTimingFixedFrames'])
      : null;
    $this->rangeTiming = $rangeTiming !== null && $projectedRange >= $rangeTiming->minDistance ? $rangeTiming : null;
    if ($resultsOnly) {
      $this->flightTiming = null;
      $this->targetContactSequence = null;
      $this->hitFeedbackFrames = [];
      $this->fallbackHitSpacing = $this->impactFrame = 0;
      $this->actionPoseAnchorFrame = null;
      $this->actionPoseStartFrame = null;
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
    $this->fallbackHitSpacing = $fallbackHitTrack === null ? 0
      : $targetTiming->getFrameBoundary($target->defaults['fallbackHitSpacingFrames'], self::FPS);
    $fallbackHitTail = max(0, $targetCount - 1) * $this->fallbackHitSpacing;
    $this->terminalTiming = $this->terminalTarget === null ? null
      : EffectPlaybackTiming::createForBattlePhase($this->terminalTarget, $timings->effectAnimation);
    $phases = $cues = $segments = $restFrames = $restSegments = [];
    $detachments = $targetGroupCaptures = [];
    $impact = 0;
    $effectEnd = 0;
    $overlapEffectTail = $flashStep->overlapEffectTail ?? false;
    $poseAnchor = null;
    $poseEnd = null;
    $holdEnd = null;
    $transition = static fn(?BattlerPose $pose, BattlePoseSequence $sequence, string $phase): array =>
      $movementPoses && $pose !== null && $pose->getSequenceSeconds($sequence) > 0
        ? [$phase => $pose->getSequenceSeconds($sequence)] : [];
    foreach ([...($flashStep === null ? [] : [
        'announce' => $flashStep->guardFrames / $flashStep->fps, 'guard' => $flashStep->guardFrames / $flashStep->fps]),
      ...$transition($movePose, BattlePoseSequence::ENTRY, 'enter-advance'),
      'advance' => $flashStep !== null ? $flashStep->advanceFrames / $flashStep->fps
        : ($travel === null ? $timings->stepForward : ($travel->isPossible ? $travel->advanceSeconds : 0)),
      ...$transition($movePose, BattlePoseSequence::EXIT, 'exit-advance'),
      ...($flashStep === null ? ['announce' => $timings->announcement] : []),
      'source' => $flashStep !== null ? 0 : ($sourceEffectAnchor === SourceEffectAnchor::TARGET ? $timings->actionAnimation
        : ($sourceTiming?->durationSeconds ?? $timings->actionAnimation)),
      ...($this->summon === null || $this->cinematicStage !== null ? [] : [
        'summon-in' => max(0, (int)($this->summon->transitionCache['in']['durationMs'] ?? 0)) / 1000,
        'summon-title' => trim(strval($this->summon->defaults['name'] ?? '')) === '' ? 0 : .8,
      ]),
      'target' => max($targetTiming?->durationSeconds ?? ($flashStep === null ? $timings->effectAnimation : 0),
        $this->terminalTiming?->durationSeconds ?? 0),
      ...($this->summon === null || $this->cinematicStage !== null ? [] : [
        'summon-out' => max(0, (int)($this->summon->transitionCache['out']['durationMs'] ?? 0)) / 1000,
      ]),
      'reaction' => $flashStep === null ? $timings->statChanges : 0,
      ...($movementPoses || $travel !== null ? ['recovery' => 0] : []),
      ...$transition($returnPose, BattlePoseSequence::ENTRY, 'enter-return'),
      'return' => $flashStep !== null ? $flashStep->returnFrames / $flashStep->fps
        : ($travel === null ? $timings->stepBack : ($travel->isPossible ? $travel->returnSeconds : 0)),
      ...$transition($returnPose, BattlePoseSequence::EXIT, 'exit-return'),
      'finish' => $flashStep !== null ? $flashStep->settleFrames / $flashStep->fps : $timings->turnOver,
      ...($overlapEffectTail ? ['effect-tail' => 0] : [])] as $phase => $seconds) {
      if (!is_finite($seconds) || $seconds < 0) {
        throw new InvalidArgumentException('Battle command durations must be finite and non-negative.');
      }
      $clock = match ($phase) {
        'source' => $sourceEffectAnchor === SourceEffectAnchor::TARGET ? null : $sourceTiming,
        'target' => $targetTiming, default => null };
      $length = $phase === 'announce' && !$showAnnouncement ? 0
        : max(1, $clock?->getFrameBoundary($clock->totalFrames, self::FPS) ?? (int)ceil($seconds * self::FPS));
      if ($flashStep !== null && in_array($phase, ['source', 'reaction'], true)) { $length = 0; }
      if ($phase === 'reaction') { $length += $fallbackHitTail; }
      if ($phase === 'target') {
        $terminalLength = $this->terminalTiming?->getFrameBoundary($this->terminalTiming->totalFrames, self::FPS) ?? 0;
        $length = max($length, $terminalLength);
        $impact = $cursor + self::getTargetImpactOffset($target, $targetTiming, $length);
        if ($sourceEffectAnchor === SourceEffectAnchor::TARGET && $sourceTiming !== null) {
          $length = max($length, $sourceTiming->getFrameBoundary($sourceTiming->totalFrames, self::FPS));
        }
        $effectEnd = $cursor + $length;
        $syncFrame = $target?->defaults['poseSyncFrame'] ?? null;
        if ($syncFrame !== null && (!is_int($syncFrame) || $syncFrame < 0 || $syncFrame >= $targetTiming->totalFrames)) {
          throw new InvalidArgumentException('Battle action pose synchronization requires a valid target effect frame.');
        }
        $poseAnchor = $cursor + ($syncFrame === null ? 0 : $targetTiming->getFrameBoundary($syncFrame, self::FPS));
        if ($flashStep !== null) {
          $poseAnchor = $cursor;
          $holdLength = (int)ceil($flashStep->holdFrames * self::FPS / $flashStep->fps);
          $holdEnd = $cursor + $holdLength;
          // Preserve the impact and Terminal presentation before letting effects run through the return.
          if ($overlapEffectTail) {
            $length = min($length, max($holdLength, $terminalLength, $impact - $cursor));
          }
        }
        if ($actionPose?->impactFrame !== null) {
          $recovery = $actionPose->getRecoverySeconds($travel?->usesFarPose ?? false, $travel?->arrivalPose);
          $poseEnd = $poseAnchor + (int)ceil($recovery * self::FPS);
          if ($this->rangeTiming !== null) {
            $authoredEnd = ($poseEnd - $cursor) / self::FPS / $targetTiming->secondsPerFrame;
            $poseEnd = $cursor + $targetTiming->getFrameBoundary(
              $this->rangeTiming->getPresentationFrame($authoredEnd), self::FPS);
          }
        }
      }
      // An authored walk home starts after anchored action recovery, preserving all impact/effect cues.
      if ($phase === 'recovery') { $length = max(0, ($poseEnd ?? $cursor) - $cursor, ($holdEnd ?? $cursor) - $cursor); }
      if ($phase === 'return' && $flashStep !== null) { $poseEnd = $cursor; }
      // Without movement artwork, retain the existing overlapping return/completion timing.
      if ($phase === 'finish' && $poseEnd !== null) { $length = max($length, $poseEnd - $cursor); }
      if ($phase === 'effect-tail') { $length = max(0, $effectEnd - $cursor); }
      $phases[$phase] = ['start' => $cursor, 'length' => $length];
      if ($length > 0 || ($flashStep !== null && $phase === 'source')) {
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
        if ($lane === 'target' && isset($effect->defaults['targetCaptureFrame'])) {
          $cues[] = ['id' => 'capture-target-snapshot', 'type' => 'commandCaptureTargetSnapshot',
            'frame' => $cursor + $clock->getFrameBoundary($effect->defaults['targetCaptureFrame'], self::FPS), 'payload' => []];
        }
        if ($lane === 'target' && isset($effect->defaults['targetContactSequence'])) {
          $contact = $effect->defaults['targetContactSequence'];
          $targetContacts = new BattleTargetContactSequence(
            $cursor + $clock->getFrameBoundary($contact['firstFrame'], self::FPS),
            $cursor + $clock->getFrameBoundary($contact['lastFrame'], self::FPS),
            BattleContactDirection::from($contact['direction']),
            array_map(static fn(string $track): string => 'target-' . $track, $contact['tracks']));
          $cues[] = ['id' => 'capture-target-contacts', 'type' => 'commandCaptureTargetContacts',
            'frame' => $targetContacts->firstFrame, 'payload' => []];
        }
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
            if (($command['payload']['anchorLifetime'] ?? null) === EffectAnchorLifetime::TARGET_CAPTURE->value) {
              $command['payload']['capturedTargetGroup'] = 'snapshot';
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
                ...(isset($flight['contact']) ? ['contact' => $flight['contact']] : []),
                ...(isset($flight['fallback']) ? ['fallback' => $flight['fallback'],
                  'arrival' => $cursor + $boundary($flight['arrivalFrame'])] : []),
                'curve' => $visualClock !== null ? ($flight['farCurve'] ?? $flight['curve'] ?? null) : ($flight['curve'] ?? null),
                ...(!isset($flight['bystanderClearance']) ? [] : ['clearance' => (float)$flight['bystanderClearance']
                  + max($command['payload']['profile']['startRadius'], $command['payload']['profile']['endRadius'])])]];
          }
          unset($command);
          $copy['startFrame'] = $cursor + $boundary($segment['startFrame']);
          $copy['endFrame'] = $cursor + $boundary($segment['endFrame'] + 1) - 1;
          if ($copy['layer'] === 'tether') {
            // Reduced motion can hold this sample beyond its ordinary interval.
            foreach ($copy['drawCommands'] as &$command) {
              $command['payload']['contactWindow'] = [$copy['startFrame'], $copy['endFrame']];
            }
            unset($command);
          }
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
            $laneEnd = $phase === 'target' ? $effectEnd : $cursor + $length;
            $segments[] = ['startFrame' => $cue['frame'], 'endFrame' => min($laneEnd, $cue['frame'] + $duration) - 1,
              'layer' => strtolower($cue['type']), 'drawCommands' => [['trackId' => $lane . '-cue-' . $cue['id'],
                'payload' => $cue['payload']]]];
          }
          $cues[] = $cue;
        }
      }
      // Staged actions and summons start as their callout appears. Reading time
      // owns visibility while each authored lane keeps its own clock.
      if ($phase !== 'announce' || ($flashStep === null && $this->summon === null && $stages === [])) { $cursor += $length; }
    }
    if (($flashStep !== null || $this->summon !== null || $stages !== []) && $showAnnouncement) {
      $cues[] = ['id' => 'command-announcement-end', 'type' => 'commandAnnouncementEnd',
        'frame' => min($phases['announce']['start'] + $phases['announce']['length'], $phases['finish']['start']),
        'payload' => []];
    }
    $this->targetContactSequence = $targetContacts;
    $flightTiming = $target?->defaults['flightTiming'] ?? null;
    $release = $flightTiming === null ? null
      : $phases['target']['start'] + $targetTiming->getFrameBoundary($flightTiming['release'], self::FPS);
    if ($release !== null && $release >= $impact) {
      throw new InvalidArgumentException('Battle flight needs separate release and impact ticks.');
    }
    $this->flightTiming = $flightTiming === null ? null : [
      'track' => 'target-' . $flightTiming['track'],
      'release' => $release,
      'arrival' => $impact,
      'speed' => $flightTiming['speed'],
    ];
    if ($this->usesArenaImpact && $this->cinematicStage !== null) {
      $cues[] = ['id' => 'command-arena-restored', 'type' => 'commandArenaRestored',
        'frame' => $phases['target']['start'] + $targetTiming->getFrameBoundary($this->cinematicStage->data['restoreFrame'], self::FPS),
        'payload' => []];
    }
    if ($fallbackHitTrack !== null && ($detachments[$fallbackHitTrack]['payload']['arrival'] ?? null) !== $impact) {
      throw new InvalidArgumentException('The horizontal exit arrival must own the spaced battle impact.');
    }
    $this->impactFrame = $impact;
    $cues[] = ['id' => 'command-impact', 'type' => 'commandImpact', 'frame' => $impact, 'payload' => []];
    $cues = [...$cues, ...array_values($detachments), ...array_values($targetGroupCaptures)];
    $order = static fn(array $cue): int => match ($cue['type']) {
      'commandCapturePosePoint', 'commandCaptureTargetGroup', 'commandCaptureTargetContacts', 'commandArenaRestored' => 0, 'commandImpact' => 1, default => 2,
    };
    usort($cues, static fn(array $a, array $b): int => [$a['frame'], $order($a)] <=> [$b['frame'], $order($b)]);
    $this->actionPoseAnchorFrame = $poseAnchor;
    $this->actionPoseStartFrame = $targetPoseClock ? $phases['target']['start'] : null;
    $this->actionPoseEndFrame = $poseEnd;
    $this->phases = $phases;
    $this->restFrames = $restFrames;
    $this->restSegments = $restSegments;
    $hitFeedback = isset($target->defaults['hitFeedbackFrames'])
      ? BattleRecordedHitTimeline::readFrames($target->defaults['hitFeedbackFrames'], $targetTiming->totalFrames) : [];
    $this->hitFeedbackFrames = array_map(fn(int $frame): int =>
      $this->getCommandFrameForAuthoredFrame('target', $frame)
        ?? throw new InvalidArgumentException('Hit feedback must fit the target effect clock.'), $hitFeedback);
    if ($this->hitFeedbackFrames !== []) {
      BattleRecordedHitTimeline::readFrames($this->hitFeedbackFrames, $phases['return']['start']);
    }
    if ($this->hitFeedbackFrames !== [] && ($this->hitFeedbackFrames[0] !== $impact
      || $this->hitFeedbackFrames[array_key_last($this->hitFeedbackFrames)] >= $phases['return']['start'])) {
      throw new InvalidArgumentException('Hit feedback must begin at the single impact and finish before return.');
    }
    $this->timeline = new CompiledEffectTimeline('battle-command', '', fps: self::FPS,
      playbackSegments: $segments, cueSchedule: $cues,
      defaults: ['lengthFrames' => $cursor, 'restFrame' => $cursor - 1]);
  }

  /** Summons retain their explicit cue/frame/end policy; ordinary effects may author an impact cue. */
  private static function getTargetImpactOffset(?CompiledEffectTimeline $target, ?EffectPlaybackTiming $clock, int $length): int
  {
    $timing = $target?->defaults['effectTiming'] ?? null;
    $mode = $timing['mode'] ?? null;
    if ($mode === 'explicit_frame' || (is_string($mode) && EffectImpactTimingMode::tryFrom($mode)?->usesFrame())) {
      return min($length, $clock->getFrameBoundary((int)($timing['frame'] ?? 0), self::FPS));
    }
    if (!is_array($timing) || ($timing['mode'] ?? 'end') === 'cue') {
      $cueId = $timing['cueId'] ?? null;
      $cue = array_find($target?->cueSchedule ?? [], static fn(array $cue): bool => $cueId === null
        ? $cue['type'] === 'applyEffect' : $cue['id'] === $cueId);
      if ($cue !== null) { return min($length, $clock->getFrameBoundary($cue['frame'], self::FPS)); }
    }
    return $length;
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
