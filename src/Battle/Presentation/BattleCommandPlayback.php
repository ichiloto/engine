<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Throwable;

/**
 * Combat resolves once at impact; inspection and rendering are side-effect free.
 * @phpstan-type DetachedPosePoint array{point: Vector2, casterX: float, recipientX: ?float, party: bool, path?: BattlePresentationPath}
 */
final class BattleCommandPlayback implements BattleEffectPlayback
{
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
  /** @var ?Closure(self, string, string, ?string, ?array, ?float, ?string): array<int, DetachedPosePoint> */
  private ?Closure $capturePosePoints = null;
  /** @var array<string, array<int, DetachedPosePoint>> */
  private array $detachedPosePoints = [];
  /** @var ?Closure(self): ?BattleEffectTargetGroup */
  private ?Closure $captureTargetGroup = null;
  /** @var array<string, ?BattleEffectTargetGroup> */
  private array $targetGroups = [];
  /** @var array<int, array{battler: \Ichiloto\Engine\Entities\Enemies\Enemy, start: float, cleared: bool}> */
  private array $defeats = [];

  public bool $isCompleted { get => $this->cancelled || ($this->session->isCompleted
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
    $duration = $this->session->timing->durationSeconds;
    $this->elapsedSeconds = min($duration + $this->defeatStyle->getDurationSeconds(),
      $this->elapsedSeconds + max(0, $seconds));
    $update = $this->session->update(min(max(0, $seconds), $duration));
    $this->dispatch($update->crossedCues);
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

  /** Abandonment removes visuals, never executes a command that has not hit. */
  public function cancel(): void
  {
    $this->cancelled = true;
    $this->defeats = $this->detachedPosePoints = [];
    $this->capturePosePoints = null;
    $this->targetGroups = [];
    $this->captureTargetGroup = null;
  }

  /** @param Closure(self, string, string, ?string, ?array, ?float, ?string): array<int, DetachedPosePoint> $capture */
  public function bindPosePointCapture(Closure $capture): void
  {
    $this->capturePosePoints = $capture;
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
  public function pause(): void { $this->paused = true; $this->session->pause(); }
  public function resume(): void { $this->paused = false; $this->session->resume(); }

  public function beginEnemyDefeat(\Ichiloto\Engine\Entities\Enemies\Enemy $battler): void
  {
    if ($this->cancelled || !$battler->isKnockedOut
      || ($battler !== $this->actor && !in_array($battler, $this->targets, true))) { return; }
    $this->defeats[spl_object_id($battler)] ??= ['battler' => $battler,
      'start' => $this->plan->phases['return']['start'] / BattleCommandTimeline::FPS, 'cleared' => false];
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
      return $this->isBoundActionPoseActive() ? $this->pose : $rest;
    }
    if (($this->phase === 'return' || ($this->phase === 'finish'
      && $this->plan->actionPoseEndFrame !== null && $this->session->currentFrame < $this->plan->actionPoseEndFrame))
      && $battler === $this->actor
      && !isset($this->reactions[spl_object_id($battler)]) && $poses?->getPose($this->pose)?->impactFrame !== null) {
      return $this->pose;
    }
    if (!in_array($this->phase, ['advance', 'return', 'finish'], true)) {
      return $this->reactions[spl_object_id($battler)] ?? ($battler === $this->actor ? $this->pose : $rest);
    }
    return $rest;
  }

  public function getBoundActionPose(CharacterInterface $battler, BattlePoseRole $role): ?BattlerPose
  {
    return $battler === $this->actor && $role === $this->pose && !isset($this->reactions[spl_object_id($battler)])
      && $this->isBoundActionPoseActive() ? $this->plan->actionPoseOverride : null;
  }

  private function isBoundActionPoseActive(): bool
  {
    if ($this->plan->actionPoseOverride === null || $this->plan->actionPoseEndFrame === null
      || $this->isCompleted || $this->phase === 'advance' || $this->phase === 'enter-advance'
      || $this->phase === 'exit-advance' || $this->session->currentFrame >= $this->plan->actionPoseEndFrame) {
      return false;
    }
    return $this->plan->actionPoseOverride->impactFrame !== 0
      || $this->plan->getActionPoseClockFrame($this->session->currentFrame) >= $this->plan->actionPoseAnchorFrame;
  }

  /** Pose animation reads the action playhead, never wall time or redraw count. */
  public function getPoseElapsedSeconds(CharacterInterface $battler, ?BattlePoseRole $role = null): float
  {
    if ($battler === $this->actor && in_array($role, [BattlePoseRole::MOVE, BattlePoseRole::RETURNING], true)
      && in_array($this->phase, ['advance', 'return', 'enter-advance', 'exit-advance', 'enter-return', 'exit-return'], true)) {
      return max(0, ($this->session->currentFrame - $this->plan->phases[$this->phase]['start']) / BattleCommandTimeline::FPS);
    }
    $reaction = $this->reactionFrames[spl_object_id($battler)] ?? null;
    $start = $reaction ?? ($this->plan->phases['announce']['start'] ?? 0);
    $frame = $battler === $this->actor && $reaction === null && $role === $this->pose
      ? $this->plan->getActionPoseClockFrame($this->session->currentFrame) : $this->session->currentFrame;
    return max(0, ($frame - $start) / BattleCommandTimeline::FPS);
  }

  /** Stationary transition sequences are selected independently of the distance-based main sequence. */
  public function getPoseSequence(CharacterInterface $battler, BattlePoseRole $role): BattlePoseSequence
  {
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
    if ($battler !== $this->actor || isset($this->reactionFrames[spl_object_id($battler)])
      || $this->plan->actionPoseAnchorFrame === null || !isset($this->plan->phases['announce'])) { return null; }
    return max(0, ($this->plan->actionPoseAnchorFrame - $this->plan->phases['announce']['start'])
      / BattleCommandTimeline::FPS);
  }

  /** Drawing may hold a rest frame, but never seek the logical cue playhead. */
  public function getActiveSegments(bool $reducedMotion = false, bool $terminal = false): array
  {
    if ($this->isCompleted) { return []; }
    $frame = $this->session->currentFrame;
    $segments = $reducedMotion && isset($this->plan->restSegments[$this->phase])
      ? $this->plan->restSegments[$this->phase] : $this->session->getActiveSegments($frame);
    $presentation = $terminal ? EffectPresentation::TERMINAL : EffectPresentation::GRAPHICAL;
    $segments = array_values(array_filter($segments, $presentation->acceptsSegment(...)));
    $fallback = $this->plan->terminalTarget;
    if ($terminal && $this->phase === 'target' && $fallback !== null) {
      $sourceFrame = $reducedMotion ? ($fallback->defaults['restFrame'] ?? 0)
        : min(max(0, (int)($fallback->defaults['lengthFrames'] ?? 1) - 1),
          $this->plan->terminalTiming->getFrameCountAt(($frame - $this->plan->phases['target']['start']) / BattleCommandTimeline::FPS));
      foreach ($this->plan->terminalSegments as $segment) {
        if ($presentation->acceptsSegment($segment) && in_array($segment['layer'], ['glyph', 'text'], true)
          && $segment['startFrame'] <= $sourceFrame && $sourceFrame <= $segment['endFrame']) {
          $segments[] = $segment;
        }
      }
    }
    return \Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition::compose($segments, $presentation);
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
    if ($frame <= $advance['start']) { return 0.0; }
    if ($frame < $advance['start'] + $advance['length']) {
      return ($frame - $advance['start']) / $advance['length'];
    }
    if ($frame >= $return['start']) {
      return max(0, 1 - ($frame - $return['start']) / $return['length']);
    }
    return 1;
  }

  /** Short damage recoil is presentation-only and pauses with the command. */
  public function getRecoilFraction(CharacterInterface $battler, bool $reducedMotion): float
  {
    if ($reducedMotion || $this->getPoseRole($battler) !== BattlePoseRole::DAMAGE) { return 0; }
    $elapsed = $this->getPoseElapsedSeconds($battler);
    return $elapsed >= .3 ? 0 : sin($elapsed * 80) * .025 * (1 - $elapsed / .3);
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
              $payload['curve'], $payload['clearance'] ?? null, $payload['contact'] ?? null);
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
    foreach ($this->plan->phases as $phase => $range) {
      if ($range['length'] > 0 && $range['start'] <= $frame) { $sample->phase = $phase; }
    }
    $sample->reactions = $this->reactions;
    $sample->reactionFrames = $this->reactionFrames;
    return $sample;
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
