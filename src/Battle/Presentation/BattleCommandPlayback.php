<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectStraightFlightFallback;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Throwable;

/**
 * Combat resolves once at impact; inspection and rendering are side-effect free.
 * @phpstan-type DetachedPosePoint array{point: Vector2, casterX: float, recipientX: ?float, party: bool, path?: BattlePresentationPath, fallback?: ?EffectStraightFlightFallback, targetOrder?: list<int>}
 */
final class BattleCommandPlayback implements BattleEffectPlayback
{
  /** Maximum displacement as a share of the recoiling battler's slot width. */
  public const float DAMAGE_RECOIL_FRACTION = .025;

  public readonly EffectPlaybackSession $session;
  private Closure $resolve;
  private Closure $presentCue;
  private bool $resolved = false;
  private bool $cancelled = false;
  public private(set) string $phase = 'advance';
  public private(set) ?Throwable $presentationFailure = null;
  /** @var array<int, BattlePoseRole> Reactions from actual resolved outcomes, keyed by combatant instance. */
  private array $reactions = [];
  private array $reactionFrames = [];
  private ?int $impactFrame = null;
  private float $elapsedSeconds = 0;
  private bool $paused = false;
  private ?BattleFlightClock $flightClock = null;
  private ?float $arenaWidth = null;
  /** @var ?Closure(self, string, string, ?string, ?array, ?float, ?string, ?string): array<int, DetachedPosePoint> */
  private ?Closure $capturePosePoints = null;
  /** @var array<string, array<int, DetachedPosePoint>> */
  private array $detachedPosePoints = [];
  /** @var array<int, int> Selected combatant identity to command impact frame, in presentation order. */
  private array $fallbackHitFrames = [];
  /** @var array<int, int> Graphical contact frames; gameplay and reactions keep their own impact. */
  private array $targetContactFrames = [];
  /** @var ?Closure(self): ?BattleEffectTargetGroup */
  private ?Closure $captureTargetGroup = null;
  /** @var array<string, ?BattleEffectTargetGroup> */
  private array $targetGroups = [];
  /** @var array<int, array{battler: \Ichiloto\Engine\Entities\Enemies\Enemy, start: float, cleared: bool}> */
  private array $defeats = [];
  /** @var list<array{subject: int, start: float, timing: EffectPlaybackTiming, timeline: CompiledEffectTimeline, presentation: EffectPresentation}> */
  private array $resultEffects = [];
  private float $resultEffectsEnd = 0;

  public bool $isCompleted { get => $this->cancelled || ($this->session->isCompleted
    && $this->elapsedSeconds >= $this->resultEffectsEnd
    && ($this->flightClock === null || $this->elapsedSeconds >= $this->getDurationSeconds())
    && !array_any($this->defeats, static fn(array $defeat): bool => !$defeat['cleared'])); }
  public bool $isCancelled { get => $this->cancelled; }

  /** @param list<CharacterInterface> $targets */
  public function __construct(public readonly BattleCommandTimeline $plan,
    public readonly CharacterInterface $actor, public readonly array $targets,
    public readonly BattlePoseRole $pose, callable $resolve, callable $presentCue,
    public readonly EnemyDefeatStyle $defeatStyle = new EnemyDefeatStyle())
  {
    $this->session = new EffectPlaybackSession($plan->timeline, false);
    $this->resolve = Closure::fromCallable($resolve);
    $this->presentCue = Closure::fromCallable($presentCue);
  }

  public function begin(): void { $this->dispatch($this->session->takeCurrentFrameCues()); }

  public function update(float $seconds): void
  {
    if (!is_finite($seconds)) { throw new \InvalidArgumentException('Battle elapsed time must be finite.'); }
    // A completed effect session cannot resume; the command still owns its defeat hold.
    if ($this->paused || ($this->session->isPaused && !$this->session->isCompleted)) { return; }
    if ($this->isCompleted) { return; }
    $remaining = max(0, $seconds);
    $release = $this->plan->flightTiming['release'] ?? null;
    if ($this->arenaWidth !== null && $release !== null) {
      $releaseSeconds = $release / BattleCommandTimeline::FPS;
      $untilRelease = $releaseSeconds - $this->elapsedSeconds;
      if ($untilRelease > 0 && $remaining >= $untilRelease) {
        // The release cue captures the path before this update can cross the impact.
        $this->advanceClock($untilRelease);
        if ($this->cancelled || $this->paused) { return; }
        $remaining -= $untilRelease;
      }
    }
    $this->advanceClock($remaining);
    if ($this->session->isCompleted) { $this->resolveOnce(); }
    foreach ($this->defeats as $identity => $defeat) {
      if ($this->cancelled) { return; }
      if (!$defeat['battler']->isKnockedOut) { unset($this->defeats[$identity]); continue; }
      if (!$defeat['cleared'] && $this->elapsedSeconds >= $defeat['start'] + $this->defeatStyle->getDurationSeconds()) {
        // Mark before delivery: a failed or reentrant audio sink is never retried.
        $this->defeats[$identity]['cleared'] = true;
        $this->presentCue(['type' => 'enemyDefeated', 'frame' => $this->session->currentFrame,
          'payload' => ['target' => $defeat['battler']]]);
      }
    }
  }

  private function advanceClock(float $seconds): void
  {
    $previous = $this->elapsedSeconds;
    $this->elapsedSeconds = min($this->getDurationSeconds() + $this->defeatStyle->getDurationSeconds(), $previous + $seconds);
    $duration = $this->session->timing->durationSeconds;
    if ($this->flightClock === null) {
      $advance = min($seconds, $duration);
    } else {
      // Absolute frame boundaries avoid subtracting nearly equal authored times
      // when a short update reaches the far flight's exact impact.
      $authored = min($duration, $this->flightClock->getAuthoredSeconds($this->elapsedSeconds));
      $targetFrame = $this->session->timing->getFrameCountAt($authored);
      $advance = max(0, $targetFrame - $this->session->currentFrame) / BattleCommandTimeline::FPS;
    }
    $this->dispatch($this->session->update($advance)->crossedCues);
    // A result cue can extend presentation while crossing this update.
    $this->elapsedSeconds = max($this->elapsedSeconds, min($this->resultEffectsEnd, $previous + $seconds));
  }

  public function getDurationSeconds(): float
  {
    $duration = $this->session->timing->durationSeconds;
    $commandEnd = $this->flightClock === null ? $duration : max($this->flightClock->getElapsedSeconds($duration),
      ($this->plan->actionPoseEndFrame ?? 0) / BattleCommandTimeline::FPS);
    return max($commandEnd, $this->resultEffectsEnd);
  }

  /** The elapsed boundary at which an authored command frame can first be observed. */
  public function getElapsedSecondsAtFrame(int $frame): float
  {
    $authored = $frame / BattleCommandTimeline::FPS;
    return $this->flightClock?->getElapsedSeconds($authored) ?? $authored;
  }

  /** Abandonment removes visuals, never executes a command that has not hit. */
  public function cancel(): void
  {
    $this->cancelled = true;
    $this->targetContactFrames = [];
    $this->defeats = $this->detachedPosePoints = $this->fallbackHitFrames = [];
    $this->resultEffects = [];
    $this->resultEffectsEnd = 0;
    $this->capturePosePoints = null;
    $this->targetGroups = [];
    $this->captureTargetGroup = null;
  }

  /** @param Closure(self, string, string, ?string, ?array, ?float, ?string, ?string): array<int, DetachedPosePoint> $capture */
  public function bindPosePointCapture(Closure $capture, ?float $arenaWidth = null): void
  {
    $this->capturePosePoints = $capture;
    $this->arenaWidth = $arenaWidth;
  }

  /** @param Closure(self): ?BattleEffectTargetGroup $capture */
  public function bindTargetGroupCapture(Closure $capture): void
  {
    $this->captureTargetGroup = $capture;
  }

  public function getCapturedTargetGroup(string $lane): ?BattleEffectTargetGroup
  {
    return $this->targetGroups[$lane] ?? null;
  }

  /** @return ?DetachedPosePoint */
  public function getDetachedPosePoint(string $trackId, CharacterInterface $subject): ?array
  {
    $point = $this->detachedPosePoints[$trackId][spl_object_id($subject)] ?? null;
    if ($point === null) { return null; }
    $point['point'] = clone $point['point'];
    return $point;
  }
  /** @return array<int, int> Each selected target's presentation beat when the horizontal exit was used. */
  public function getFallbackHitFrames(): array
  {
    return $this->fallbackHitFrames;
  }

  /** @return array<int, int> Selected identities in their captured front-crossing order. */
  public function getTargetContactFrames(): array { return $this->targetContactFrames; }

  public function isAwaitingFallbackHit(CharacterInterface $battler): bool
  {
    return ($this->fallbackHitFrames[spl_object_id($battler)] ?? 0) > $this->session->currentFrame;
  }

  public function pause(): void { $this->paused = true; $this->session->pause(); }
  public function resume(): void { $this->paused = false; $this->session->resume(); }

  public function beginEnemyDefeat(\Ichiloto\Engine\Entities\Enemies\Enemy $battler): void
  {
    if ($this->cancelled || !$battler->isKnockedOut
      || ($battler !== $this->actor && !in_array($battler, $this->targets, true))) { return; }
    $start = $this->plan->phases['return']['start'] / BattleCommandTimeline::FPS;
    $this->defeats[spl_object_id($battler)] ??= ['battler' => $battler,
      'start' => $this->flightClock?->getElapsedSeconds($start) ?? $start, 'cleared' => false];
  }

  public function getEnemyDefeatTreatment(CharacterInterface $battler, bool $reducedMotion = false): ?array
  {
    $defeat = $this->defeats[spl_object_id($battler)] ?? null;
    return $defeat === null || !$battler->isKnockedOut ? null
      : $this->defeatStyle->getTreatment($this->elapsedSeconds - $defeat['start'], $reducedMotion);
  }

  public function recordPresentationFailure(Throwable $failure): void
  {
    $this->presentationFailure ??= $failure;
  }

  public function setReaction(CharacterInterface $battler, BattlePoseRole $role, ?int $startFrame = null): void
  {
    $this->reactions[spl_object_id($battler)] = $role;
    $this->reactionFrames[spl_object_id($battler)] = $startFrame ?? $this->impactFrame ?? $this->session->currentFrame;
  }

  public function getPoseRole(CharacterInterface $battler, ?BattlePoseSet $poses = null, bool $reducedMotion = false): BattlePoseRole
  {
    $rest = BattlePoseRole::getRestingRole($battler);
    if ($rest === BattlePoseRole::KNOCKOUT && $this->isAwaitingFallbackHit($battler)) { return BattlePoseRole::IDLE; }
    if ($this->cancelled || $this->isCompleted || $rest === BattlePoseRole::KNOCKOUT || $rest === BattlePoseRole::CONCEDE) { return $rest; }
    if (!$reducedMotion && $this->plan->movementPoses && !$this->plan->resultsOnly && $battler === $this->actor
      && !isset($this->reactions[spl_object_id($battler)])) {
      $movement = match ($this->phase) {
        'advance', 'enter-advance', 'exit-advance' => BattlePoseRole::MOVE,
        'return', 'enter-return', 'exit-return' => $poses?->getPose(BattlePoseRole::RETURNING) !== null ? BattlePoseRole::RETURNING : BattlePoseRole::MOVE,
        default => null,
      };
      if ($movement !== null && $poses?->getPose($movement) !== null) { return $movement; }
    }
    if ($battler === $this->actor && $this->plan->actionPoseOverride !== null
      && !isset($this->reactions[spl_object_id($battler)])) {
      return $this->isBoundActionPoseActive($reducedMotion) ? $this->pose : $rest;
    }
    if ($battler === $this->actor && $this->plan->actionPoseStartFrame !== null
      && $this->session->currentFrame < $this->plan->actionPoseStartFrame
      && !isset($this->reactions[spl_object_id($battler)])) { return $rest; }
    if (($this->phase === 'return' || ($this->phase === 'finish'
      && $this->plan->actionPoseEndFrame !== null && $this->session->currentFrame < $this->plan->actionPoseEndFrame))
      && $battler === $this->actor
      && !isset($this->reactions[spl_object_id($battler)]) && $poses?->getPose($this->pose)?->impactFrame !== null) {
      return $this->pose;
    }
    if (!in_array($this->phase, ['advance', 'return', 'finish', 'effect-tail'], true)) {
      return $this->reactions[spl_object_id($battler)] ?? ($battler === $this->actor ? $this->pose : $rest);
    }
    return $rest;
  }

  public function getBoundActionPose(CharacterInterface $battler, BattlePoseRole $role, bool $reducedMotion = false): ?BattlerPose
  {
    return $battler === $this->actor && $role === $this->pose && !isset($this->reactions[spl_object_id($battler)])
      && $this->isBoundActionPoseActive($reducedMotion) ? $this->plan->actionPoseOverride : null;
  }

  private function isBoundActionPoseActive(bool $reducedMotion): bool
  {
    if ($this->plan->actionPoseOverride === null || $this->isCompleted) { return false; }
    if ($this->plan->flashStep !== null) {
      return in_array($this->phase, ['advance', 'source', 'target', 'reaction', 'recovery', 'return'], true)
        || ($this->plan->actionPoseOverride->guardFrame !== null && in_array($this->phase, ['guard', 'finish'], true));
    }
    $start = $this->plan->actionPoseStartFrame;
    if ($this->plan->actionPoseEndFrame === null || in_array($this->phase, ['advance', 'enter-advance', 'exit-advance'], true)
      || ($start !== null && $this->session->currentFrame < $start)) {
      return false;
    }
    if ($reducedMotion && $start !== null && (!$this->resolved || $this->session->currentFrame === $this->impactFrame)) {
      return true;
    }
    if ($this->getActorClockFrame() >= $this->plan->actionPoseEndFrame) { return false; }
    return $this->plan->actionPoseOverride->impactFrame !== 0
      || $this->getActorClockFrame() >= $this->plan->actionPoseAnchorFrame;
  }

  private function getActorClockFrame(): float
  {
    return $this->flightClock === null ? $this->plan->getActionPoseClockFrame($this->session->currentFrame)
      : $this->elapsedSeconds * BattleCommandTimeline::FPS;
  }

  /** Pose animation reads the action playhead, never wall time or redraw count. */
  public function getPoseElapsedSeconds(CharacterInterface $battler, ?BattlePoseRole $role = null): float
  {
    if ($this->plan->flashStep !== null && $role === $this->pose
      && ($pose = $this->getBoundActionPose($battler, $role)) !== null) {
      $sequence = $this->getPoseSequence($battler, $role);
      if ($sequence !== BattlePoseSequence::MAIN) {
        // The flash-step command fits its transit clip to the phase; ordinary transitions keep their authored FPS.
        $phase = $this->plan->phases[$this->phase];
        $fraction = ($this->session->currentFrame - $phase['start']) / $phase['length'];
        return $fraction * $pose->getSequenceSeconds($sequence);
      }
      return max(0, ($this->session->currentFrame - $this->plan->phases['target']['start']) / BattleCommandTimeline::FPS);
    }
    if ($battler === $this->actor && in_array($role, [BattlePoseRole::MOVE, BattlePoseRole::RETURNING], true)
      && in_array($this->phase, ['advance', 'return', 'enter-advance', 'exit-advance', 'enter-return', 'exit-return'], true)) {
      return max(0, ($this->session->currentFrame - $this->plan->phases[$this->phase]['start']) / BattleCommandTimeline::FPS);
    }
    $reaction = $this->reactionFrames[spl_object_id($battler)] ?? null;
    $start = $reaction ?? ($battler === $this->actor && $role === $this->pose
      ? ($this->plan->actionPoseStartFrame ?? $this->plan->phases['announce']['start'] ?? 0)
      : ($this->plan->phases['announce']['start'] ?? 0));
    $frame = $battler === $this->actor && $reaction === null && $role === $this->pose
      ? $this->getActorClockFrame() : $this->session->currentFrame;
    return max(0, ($frame - $start) / BattleCommandTimeline::FPS);
  }

  /** Stationary transition sequences are selected independently of the distance-based main sequence. */
  public function getPoseSequence(CharacterInterface $battler, BattlePoseRole $role): BattlePoseSequence
  {
    if ($this->plan->flashStep !== null && $battler === $this->actor && $role === $this->pose) {
      return match ($this->phase) {
        'advance' => BattlePoseSequence::ENTRY,
        'return' => BattlePoseSequence::EXIT,
        'guard', 'finish' => BattlePoseSequence::GUARD,
        default => BattlePoseSequence::MAIN,
      };
    }
    if ($battler !== $this->actor || !in_array($role, [BattlePoseRole::MOVE, BattlePoseRole::RETURNING], true)) {
      return BattlePoseSequence::MAIN;
    }
    return match ($this->phase) {
      'enter-advance', 'enter-return' => BattlePoseSequence::ENTRY,
      'exit-advance', 'exit-return' => BattlePoseSequence::EXIT,
      default => BattlePoseSequence::MAIN,
    };
  }

  /** A one-shot hop spans the whole current return distance/timing without looping. */
  public function getPoseDurationSeconds(CharacterInterface $battler, BattlePoseRole $role): ?float
  {
    return $battler === $this->actor && $role === BattlePoseRole::RETURNING && $this->phase === 'return'
      ? $this->plan->phases['return']['length'] / BattleCommandTimeline::FPS : null;
  }

  /** The action pose can synchronize to a release inside the target effect, before combat impact. */
  public function getPoseImpactSeconds(CharacterInterface $battler): ?float
  {
    if ($this->plan->flashStep !== null && $battler === $this->actor) { return 0.0; }
    if ($battler !== $this->actor || isset($this->reactionFrames[spl_object_id($battler)])
      || $this->plan->actionPoseAnchorFrame === null || !isset($this->plan->phases['announce'])) { return null; }
    $start = $this->plan->actionPoseStartFrame;
    $anchor = $this->plan->actionPoseAnchorFrame;
    return max(0, ($start === null ? $anchor - $this->plan->phases['announce']['start']
      : $this->plan->getActionPoseClockFrame($anchor) - $start) / BattleCommandTimeline::FPS);
  }

  /** Drawing may hold a rest frame, but never seek the logical cue playhead. */
  public function getActiveSegments(bool $reducedMotion = false, bool $terminal = false): array
  {
    if ($this->isCompleted) { return []; }
    $frame = $this->session->currentFrame;
    $presentation = $terminal ? EffectPresentation::TERMINAL : EffectPresentation::GRAPHICAL;
    $resultEffects = $this->getResultEffectSegments($reducedMotion, $presentation);
    if ($this->session->isCompleted && $this->elapsedSeconds < $this->resultEffectsEnd) {
      return EffectSegmentComposition::compose($resultEffects, $presentation);
    }
    $rest = $reducedMotion ? ($this->plan->restSegments[$this->phase] ?? null) : null;
    $segments = $rest ?? $this->session->getActiveSegments($frame);
    $segments = array_values(array_filter($segments, $presentation->acceptsSegment(...)));
    if ($terminal && $rest !== null) {
      $segments = self::getVisibleTerminalRestSegments($segments, $this->session->getActiveSegments($frame),
        $this->plan->timeline->playbackSegments, $this->plan->restFrames);
    }
    $fallback = $this->plan->terminalTarget;
    if ($terminal && $this->phase === 'target' && $fallback !== null) {
      $sourceFrame = min(max(0, (int)($fallback->defaults['lengthFrames'] ?? 1) - 1),
        $this->plan->terminalTiming->getFrameCountAt(($frame - $this->plan->phases['target']['start']) / BattleCommandTimeline::FPS));
      $select = fn(int $sample): array => array_values(array_filter($this->plan->terminalSegments,
        static fn(array $segment): bool => EffectPresentation::TERMINAL->acceptsSegment($segment)
          && in_array($segment['layer'], ['glyph', 'text'], true)
          && $segment['startFrame'] <= $sample && $sample <= $segment['endFrame']));
      $active = $select($sourceFrame);
      $restFrame = $fallback->defaults['restFrame'] ?? 0;
      array_push($segments, ...($reducedMotion
        ? self::getVisibleTerminalRestSegments($select($restFrame), $active, $this->plan->terminalSegments, ['target' => $restFrame])
        : $active));
    }
    if (!$terminal && $reducedMotion && $this->plan->fallbackHitTrack !== null && $frame < $this->plan->impactFrame) {
      // A rest sample taken at impact must not show the hit during the approach.
      $segments = [...self::filterSpacedHitSegments($segments, false),
        ...self::filterSpacedHitSegments($this->session->getActiveSegments($frame), true)];
    }
    if (!$terminal && $this->fallbackHitFrames !== [] && $frame >= $this->plan->impactFrame) {
      $segments = $this->getSpacedHitSegments($segments, $frame);
    }
    if (!$terminal && $this->plan->targetContactSequence !== null) {
      $segments = $this->getTargetContactSegments($segments, $frame, $reducedMotion);
    }
    return [...EffectSegmentComposition::compose($segments, $presentation),
      ...EffectSegmentComposition::compose($resultEffects, $presentation)];
  }

  public function startResultEffect(CharacterInterface $target, int $frame, CompiledEffectTimeline $timeline,
    EffectPresentation $presentation): void
  {
    if ($this->cancelled || !in_array($target, $this->targets, true)) { return; }
    $start = $this->getElapsedSecondsAtFrame($frame);
    $timing = new EffectPlaybackTiming($timeline);
    $this->resultEffects[] = ['subject' => spl_object_id($target), 'start' => $start,
      'timing' => $timing, 'timeline' => $timeline, 'presentation' => $presentation];
    $this->resultEffectsEnd = max($this->resultEffectsEnd, $start + $timing->durationSeconds);
  }

  private function getResultEffectSegments(bool $reducedMotion, EffectPresentation $presentation): array
  {
    $segments = [];
    foreach ($this->resultEffects as $index => $effect) {
      if ($effect['presentation'] !== $presentation) { continue; }
      $seconds = $this->elapsedSeconds - $effect['start'];
      if ($seconds < 0 || $seconds >= $effect['timing']->durationSeconds) { continue; }
      $timeline = $effect['timeline'];
      $frame = $reducedMotion ? (int)($timeline->defaults['restFrame'] ?? 0)
        : $effect['timing']->getFrameCountAt($seconds);
      $active = array_filter($timeline->playbackSegments, static fn(array $segment): bool =>
        $segment['startFrame'] <= $frame && $frame <= $segment['endFrame']);
      foreach (EffectSegmentComposition::compose($active) as $segment) {
        $segment['clearBeforeDraw'] = false;
        foreach ($segment['drawCommands'] as &$command) {
          $command['trackId'] = 'result-' . $index . '-' . $command['trackId'];
          $command['payload']['anchor'] = 'target';
          $command['payload']['hitSubject'] = $effect['subject'];
        }
        unset($command);
        $segments[] = $segment;
      }
    }
    return $segments;
  }

  /** Sample only the authored contact tracks per recipient; inspection never delivers cues. */
  private function getTargetContactSegments(array $active, int $frame, bool $reducedMotion): array
  {
    $sequence = $this->plan->targetContactSequence;
    $select = static fn(array $command): bool => in_array($command['trackId'], $sequence->tracks, true);
    $filter = static function (array $segments, bool $contacts) use ($select): array {
      foreach ($segments as &$segment) {
        $segment['drawCommands'] = array_values(array_filter($segment['drawCommands'],
          static fn(array $command): bool => $select($command) === $contacts));
      }
      unset($segment);
      return array_values(array_filter($segments, static fn(array $segment): bool => $segment['drawCommands'] !== []));
    };
    $segments = $filter($active, false);
    foreach ($this->targetContactFrames as $id => $contactFrame) {
      $delay = $contactFrame - $sequence->firstFrame;
      $sample = $frame - $delay;
      if ($sample < $sequence->firstFrame) { continue; }
      foreach ($filter($this->session->getActiveSegments($sample), true) as $segment) {
        foreach ($segment['drawCommands'] as &$command) {
          if ($reducedMotion) {
            foreach ($this->plan->timeline->playbackSegments as $original) {
              $first = array_find($original['drawCommands'], static fn(array $draw): bool => $draw['trackId'] === $command['trackId']);
              if ($first !== null) { $command = $first; break; }
            }
          }
          $command['payload']['hitSubject'] = $id;
        }
        unset($command);
        $segment['startFrame'] += $delay;
        $segment['endFrame'] += $delay;
        $segments[] = $segment;
      }
    }
    return $segments;
  }

  /** Keep the ordinary lane and sample only its hit tracks on each recipient's own clock. */
  private function getSpacedHitSegments(array $active, int $frame): array
  {
    $segments = self::filterSpacedHitSegments($active, false);
    foreach ($this->fallbackHitFrames as $id => $hitFrame) {
      $delay = $hitFrame - $this->plan->impactFrame;
      $sample = $frame - $delay;
      if ($sample < $this->plan->impactFrame) { continue; }
      foreach (self::filterSpacedHitSegments($this->session->getActiveSegments($sample), true) as $segment) {
        foreach ($segment['drawCommands'] as &$command) { $command['payload']['hitSubject'] = $id; }
        unset($command);
        $segment['startFrame'] += $delay;
        $segment['endFrame'] += $delay;
        $segments[] = $segment;
      }
    }
    return $segments;
  }

  private static function filterSpacedHitSegments(array $segments, bool $keepHits): array
  {
    $filtered = [];
    foreach ($segments as $segment) {
      $segment['drawCommands'] = array_values(array_filter($segment['drawCommands'],
        static fn(array $command): bool => self::isSpacedHitCommand($command) === $keepHits));
      if ($segment['drawCommands'] !== []) { $filtered[] = $segment; }
    }
    return $filtered;
  }

  private static function isSpacedHitCommand(array $command): bool
  {
    $data = $command['payload'] ?? [];
    return str_starts_with($command['trackId'], 'target-') && ($data['anchor'] ?? null) === 'target'
      && !isset($data['detachFrame']) && ($data['scope'] ?? null) !== 'screen';
  }

  /** A resting glyph or label keeps its authored visibility on the command clock. */
  private static function getVisibleTerminalRestSegments(array $rest, array $active, array $authored, array $restFrames): array
  {
    $visibleTracks = [];
    foreach ($active as $segment) {
      if (!EffectPresentation::TERMINAL->acceptsSegment($segment) || !in_array($segment['layer'], ['glyph', 'text'], true)) {
        continue;
      }
      foreach ($segment['drawCommands'] as $command) {
        if ($command['visible'] ?? true) {
          $visibleTracks[self::getTerminalVisibilityTrack($segment['layer'], $command)] = true;
        }
      }
    }

    $segments = [];
    $sampledTracks = [];
    foreach ($rest as $segment) {
      if (in_array($segment['layer'], ['glyph', 'text'], true)) {
        $commands = [];
        foreach ($segment['drawCommands'] as $command) {
          $track = self::getTerminalVisibilityTrack($segment['layer'], $command);
          if (isset($visibleTracks[$track]) && ($command['visible'] ?? true)) {
            $commands[] = $command;
            $sampledTracks[$track] = true;
          }
        }
        if ($commands === []) { continue; }
        $segment['drawCommands'] = $commands;
      }
      $segments[] = $segment;
    }

    $missingTracks = array_diff_key($visibleTracks, $sampledTracks);
    if ($missingTracks === []) { return $segments; }

    // A track outside the sequence rest still needs a stationary sample.
    // Choose its nearest visible frame and keep the live clock as the visibility gate.
    $samples = [];
    foreach ($authored as $segmentIndex => $segment) {
      if (!EffectPresentation::TERMINAL->acceptsSegment($segment) || !in_array($segment['layer'], ['glyph', 'text'], true)) {
        continue;
      }
      foreach ($segment['drawCommands'] as $commandIndex => $command) {
        $track = self::getTerminalVisibilityTrack($segment['layer'], $command);
        if (!isset($missingTracks[$track]) || !($command['visible'] ?? true)) { continue; }
        $lane = str_starts_with($command['trackId'], 'source-') ? 'source' : 'target';
        $restFrame = $restFrames[$lane];
        $frame = clamp($restFrame, $segment['startFrame'], $segment['endFrame']);
        $rank = [abs($frame - $restFrame), $frame];
        if (!isset($samples[$track]) || $rank < $samples[$track]['rank']) {
          $samples[$track] = ['rank' => $rank, 'commands' => []];
        }
        if ($rank === $samples[$track]['rank']) {
          $samples[$track]['commands'][] = [$segmentIndex, $commandIndex];
        }
      }
    }
    $commandsBySegment = [];
    foreach ($samples as $sample) {
      foreach ($sample['commands'] as [$segmentIndex, $commandIndex]) {
        $commandsBySegment[$segmentIndex][$commandIndex] = true;
      }
    }
    foreach ($commandsBySegment as $segmentIndex => $commands) {
      $segment = $authored[$segmentIndex];
      $segment['drawCommands'] = array_values(array_intersect_key($segment['drawCommands'], $commands));
      $segments[] = $segment;
    }
    return $segments;
  }

  private static function getTerminalVisibilityTrack(string $layer, array $command): string
  {
    // Legacy cell IDs encode their positions. A moving cell belongs to the same animation.
    if (isset($command['payload']['legacyPosition'])) {
      $lane = str_starts_with($command['trackId'], 'source-') ? 'source' : 'target';
      return $layer . ':' . $lane . ':legacy';
    }
    return $layer . ':' . $command['trackId'];
  }

  /** Ground-anchor displacement on the command clock; returning retraces the outbound route without turning. */
  public function getTravelOffset(): \Ichiloto\Engine\Core\Vector2
  {
    $travel = $this->plan->travel;
    if ($travel === null || !$travel->isPossible || $this->cancelled || $this->isCompleted) {
      return new \Ichiloto\Engine\Core\Vector2(0, 0);
    }
    $start = $travel->getPositionAtProgress(0.0);
    $point = $travel->getPositionAtProgress($this->getAdvanceFraction());
    return new \Ichiloto\Engine\Core\Vector2($point->x - $start->x, $point->y - $start->y);
  }

  public function getAdvanceFraction(): float
  {
    if ($this->cancelled || $this->plan->resultsOnly) { return 0; }
    $frame = $this->session->currentFrame;
    $advance = $this->plan->phases['advance'];
    $return = $this->plan->phases['return'];
    $keyed = $this->plan->flashStep->placeTransitOnPoseKeys ?? false;
    if ($frame < $advance['start']) { return 0.0; }
    if ($frame < $advance['start'] + $advance['length']) {
      return $this->getTransitFraction($frame, $advance, $keyed ? BattlePoseSequence::ENTRY : null);
    }
    if ($frame >= $return['start']) {
      if ($frame >= $return['start'] + $return['length']) { return 0.0; }
      return 1 - $this->getTransitFraction($frame, $return, $keyed ? BattlePoseSequence::EXIT : null);
    }
    return 1;
  }

  /** Pose-keyed transit holds each interior sample for its entire pose cell. */
  private function getTransitFraction(int $frame, array $window, ?BattlePoseSequence $sequence): float
  {
    $elapsed = $frame - $window['start'];
    $length = $window['length'];
    if ($sequence === null) { return $elapsed / $length; }
    $count = count($this->plan->actionPoseOverride->getSequenceFrames($sequence));
    return (intdiv($elapsed * $count, $length) + 1) / ($count + 1);
  }


  /** Short damage recoil is presentation-only and pauses with the command. */
  public function getRecoilFraction(CharacterInterface $battler, bool $reducedMotion): float
  {
    if ($reducedMotion || $this->getPoseRole($battler) !== BattlePoseRole::DAMAGE) { return 0; }
    $elapsed = $this->getPoseElapsedSeconds($battler);
    return $elapsed >= .3 ? 0 : sin($elapsed * 80) * self::DAMAGE_RECOIL_FRACTION * (1 - $elapsed / .3);
  }

  public function getShakeFraction(CharacterInterface $battler, bool $reducedMotion): float
  {
    if ($reducedMotion) { return 0; }
    $offset = 0;
    foreach ($this->getActiveSegments() as $segment) {
      if ($segment['layer'] !== 'shake') { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $data = $command['payload'] ?? [];
        if (isset($data['hitSubject']) && $data['hitSubject'] !== spl_object_id($battler)) { continue; }
        $matches = match ($data['anchor'] ?? 'target') {
          'caster' => $battler === $this->actor,
          'screen', 'legacy-screen' => true,
          default => in_array($battler, $this->targets, true),
        };
        if ($matches) {
          $offset += sin(($this->session->currentFrame - $segment['startFrame']) / BattleCommandTimeline::FPS * 60)
            * clamp((float)($data['amplitude'] ?? 1), 0, 8) * .025;
        }
      }
    }
    return clamp($offset, -.2, .2);
  }

  private function dispatch(array $cues): void
  {
    foreach ($cues as $cue) {
      if ($this->cancelled) { return; }
      if ($cue['type'] === 'commandCaptureTargetContacts') {
        try {
          if ($this->captureTargetGroup === null) { throw new \RuntimeException('Target contact sequencing requires the shared body capture.'); }
          $group = ($this->captureTargetGroup)($this->samplePoseAtFrame($cue['frame']));
          if ($group !== null) { $this->targetContactFrames = $this->plan->targetContactSequence->getContactFrames($group); }
          elseif ($this->targets !== []) { throw new \RuntimeException('Target contact sequencing has no selected body capture.'); }
        } catch (Throwable $failure) { $this->recordPresentationFailure($failure); }
        continue;
      }
      if ($cue['type'] === 'commandCaptureTargetGroup') {
        if ($this->captureTargetGroup !== null) {
          try {
            $this->targetGroups[$cue['payload']['lane']] = ($this->captureTargetGroup)($this->samplePoseAtFrame($cue['frame']));
          } catch (Throwable $failure) { $this->recordPresentationFailure($failure); }
        }
        continue;
      }
      if ($cue['type'] === 'commandCapturePosePoint') {
        if ($this->capturePosePoints !== null) {
          try {
            $payload = $cue['payload'];
            $this->detachedPosePoints[$payload['trackId']] = ($this->capturePosePoints)(
              $this->samplePoseAtFrame($cue['frame']), $payload['anchor'], $payload['attachmentPoint'], $payload['route'],
              $payload['curve'], $payload['clearance'] ?? null, $payload['contact'] ?? null, $payload['fallback'] ?? null);
            if ($payload['trackId'] === $this->plan->fallbackHitTrack) {
              $shot = $this->detachedPosePoints[$payload['trackId']][spl_object_id($this->actor)] ?? null;
              if (($shot['fallback'] ?? null) === EffectStraightFlightFallback::HORIZONTAL_EXIT) {
                foreach ($shot['targetOrder'] as $index => $id) {
                  $this->fallbackHitFrames[$id] = $this->plan->impactFrame + $index * $this->plan->fallbackHitSpacing;
                }
              }
            }
            $flight = $this->plan->flightTiming;
            if ($this->arenaWidth !== null && ($flight['track'] ?? null) === $payload['trackId']) {
              $path = $this->detachedPosePoints[$payload['trackId']][spl_object_id($this->actor)]['path']
                ?? throw new \RuntimeException('The timed battle flight has no captured path.');
              $return = isset($this->plan->phases['recovery'])
                ? ($this->plan->phases['enter-return'] ?? $this->plan->phases['return'])['start'] : null;
              $this->flightClock = new BattleFlightClock($flight['release'], $flight['arrival'], $path,
                $this->arenaWidth, $flight['speed'], $return, $this->plan->actionPoseEndFrame);
            }
          } catch (Throwable $failure) { $this->recordPresentationFailure($failure); }
        }
        continue;
      }
      if ($cue['type'] === 'commandImpact') { $this->impactFrame = $cue['frame']; $this->resolveOnce(); continue; }
      if ($cue['type'] === 'commandPhase') { $this->phase = $cue['payload']['phase']; }
      try {
        $this->presentCue($cue);
      } catch (Throwable $failure) {
        // A failed sound or message surface must not lose later logical cues.
        $this->presentationFailure ??= $failure;
      }
    }
  }

  /** Evaluate the exact visual clock without invoking a command or replaying its cues. */
  private function samplePoseAtFrame(int $frame): self
  {
    $sample = new self($this->plan, $this->actor, $this->targets, $this->pose,
      static function (): void {}, static function (): void {});
    $sample->session->seek($frame);
    $sample->flightClock = $this->flightClock;
    $sample->elapsedSeconds = $this->getElapsedSecondsAtFrame($frame);
    foreach ($this->plan->phases as $phase => $range) {
      if ($range['length'] > 0 && $range['start'] <= $frame) { $sample->phase = $phase; }
    }
    $sample->reactions = $this->reactions;
    $sample->reactionFrames = $this->reactionFrames;
    $sample->fallbackHitFrames = $this->fallbackHitFrames;
    $sample->targetContactFrames = $this->targetContactFrames;
    return $sample;
  }

  /** @return list<array{sample: self, opacity: float}> Cosmetic past poses on this command's clock. */
  public function getFlashStepEchoes(): array
  {
    $count = $this->plan->flashStep?->echoes ?? 0;
    if ($count === 0 || $this->isCompleted || !in_array($this->phase, ['advance', 'return'], true)) { return []; }
    $window = $this->plan->phases[$this->phase];
    $stride = max(1, intdiv($window['length'], $count + 1));
    $echoes = [];
    for ($index = $count; $index >= 1; $index--) {
      $frame = $this->session->currentFrame - $index * $stride;
      if ($frame >= $window['start']) {
        $echoes[] = ['sample' => $this->samplePoseAtFrame($frame), 'opacity' => 0.6 * (1 - $index / ($count + 1))];
      }
    }
    return $echoes;
  }

  private function resolveOnce(): void
  {
    if ($this->resolved || $this->cancelled) { return; }
    // Set before calling gameplay so a failure is never mistaken for a retry.
    $this->resolved = true;
    ($this->resolve)();
    $this->presentCue(['id' => 'command-resolved', 'type' => 'commandResolved',
      'frame' => $this->impactFrame ?? $this->session->currentFrame, 'payload' => []]);
  }

  private function presentCue(array $cue): void
  {
    try { ($this->presentCue)($cue); }
    catch (Throwable $failure) { $this->presentationFailure ??= $failure; }
  }
}
