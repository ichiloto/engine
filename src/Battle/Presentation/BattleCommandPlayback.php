<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Throwable;

/** Combat resolves once at impact; inspection and rendering are side-effect free. */
final class BattleCommandPlayback
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

  public bool $isCompleted { get => $this->cancelled || $this->session->isCompleted; }

  /** @param list<CharacterInterface> $targets */
  public function __construct(public readonly BattleCommandTimeline $plan,
    public readonly CharacterInterface $actor, public readonly array $targets,
    public readonly BattlePoseRole $pose, callable $resolve, callable $presentCue)
  {
    $this->session = new EffectPlaybackSession($plan->timeline, false);
    $this->resolve = Closure::fromCallable($resolve);
    $this->presentCue = Closure::fromCallable($presentCue);
  }

  public function begin(): void { $this->dispatch($this->session->takeCurrentFrameCues()); }

  public function update(float $seconds): void
  {
    if ($this->isCompleted) { return; }
    $update = $this->session->update($seconds);
    $this->dispatch($update->crossedCues);
    if ($this->session->isCompleted) { $this->resolveOnce(); }
  }

  /** Abandonment removes visuals, never executes a command that has not hit. */
  public function cancel(): void { $this->cancelled = true; }
  public function pause(): void { $this->session->pause(); }
  public function resume(): void { $this->session->resume(); }

  public function recordPresentationFailure(Throwable $failure): void
  {
    $this->presentationFailure ??= $failure;
  }

  public function setReaction(CharacterInterface $battler, BattlePoseRole $role): void
  {
    $this->reactions[spl_object_id($battler)] = $role;
    $this->reactionFrames[spl_object_id($battler)] = $this->impactFrame ?? $this->session->currentFrame;
  }

  public function getPoseRole(CharacterInterface $battler): BattlePoseRole
  {
    $rest = BattlePoseRole::getRestingRole($battler);
    if ($this->cancelled || $this->isCompleted || $rest === BattlePoseRole::KNOCKOUT) { return $rest; }
    if (!in_array($this->phase, ['advance', 'return', 'finish'], true)) {
      return $this->reactions[spl_object_id($battler)] ?? ($battler === $this->actor ? $this->pose : $rest);
    }
    return $rest;
  }

  /** Pose animation reads the action playhead, never wall time or redraw count. */
  public function getPoseElapsedSeconds(CharacterInterface $battler): float
  {
    $start = $this->reactionFrames[spl_object_id($battler)] ?? ($this->plan->phases['announce']['start'] ?? 0);
    return max(0, ($this->session->currentFrame - $start) / BattleCommandTimeline::FPS);
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
      foreach ($fallback->playbackSegments as $segment) {
        if ($presentation->acceptsSegment($segment) && in_array($segment['layer'], ['glyph', 'text'], true)
          && $segment['startFrame'] <= $sourceFrame && $sourceFrame <= $segment['endFrame']) {
          $segments[] = $segment;
        }
      }
    }
    return \Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition::compose($segments, $presentation);
  }

  public function getAdvanceFraction(): float
  {
    if ($this->cancelled || $this->plan->resultsOnly) { return 0; }
    $frame = $this->session->currentFrame;
    $advance = $this->plan->phases['advance'];
    $return = $this->plan->phases['return'];
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
