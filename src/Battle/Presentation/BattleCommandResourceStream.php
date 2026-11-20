<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Rendering\Assets\{AssetHoldLeases, AssetHoldPrefetch, AssetHoldPreparation, AssetHoldPreparationState, AssetHoldWindow};
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use InvalidArgumentException;

/** A command's rolling image residency. Playback owns time and cues; this owner only approves resource-safe advances. */
final class BattleCommandResourceStream
{
  public readonly AssetHoldPreparation $initialPreparation;
  private readonly AssetHoldWindow $window;
  private array $returns;
  private array $scaledField = [];
  private ?int $lastScaledFrame = null;
  private array $firstImages = [];
  private float $firstImageSeconds = 0;
  private ?float $waitingAt = null;
  private ?float $lookaheadAt = null;
  private ?float $scanAgainAfter = null;
  private bool $initialReady = false;
  public private(set) bool $didResumeWait = false;
  private bool $reducedMotion;

  /** @param Closure(): array $collectReturns @param Closure(): array $sharedRequests @param Closure(): bool $prefersReducedMotion */
  public function __construct(private readonly BattleCommandPlayback $playback, AssetHoldLeases $leases,
    private readonly string $assetRoot, private readonly Closure $collectReturns,
    private readonly Closure $sharedRequests, private readonly Closure $prefersReducedMotion,
    AssetHoldPrefetch $prefetch, float $startedAt, private readonly ?Closure $collectScaledField = null)
  {
    $this->reducedMotion = $prefersReducedMotion();
    $this->returns = $collectReturns();
    $this->refreshScaledField();
    $this->findFirstImages();
    $this->initialPreparation = $prefetch->prepareResources(
      $this->resourcesAt(0), $startedAt, null, $sharedRequests());
    $this->window = new AssetHoldWindow($leases, $this->initialPreparation);
  }

  /** The first painted cohort is ready before entry cues, even when the command opens with a blank prelude. */
  public function prepareInitialAt(float $now): AssetHoldPreparationState
  {
    $this->syncMotionMode();
    $state = $this->selectRequiredResourcesAt(0, $now);
    if ($state === AssetHoldPreparationState::READY) { $this->initialReady = true; }
    return $state;
  }

  /** Return only elapsed time whose crossed image cohorts are ready. Wait time never becomes catch-up time. */
  public function approveElapsedSeconds(float $seconds, float $now, array $decisionTimes = []): float
  {
    $this->didResumeWait = false;
    if (!is_finite($seconds) || !is_finite($this->playback->elapsedSeconds + max(0, $seconds))) {
      throw new InvalidArgumentException('Resource-safe command advancement requires finite elapsed seconds.');
    }
    if ($this->playback->isPaused || $this->playback->isCompleted || !$this->initialReady) { return 0; }
    $this->syncMotionMode();
    $current = $this->playback->elapsedSeconds;
    if ($this->waitingAt !== null) {
      $at = $this->waitingAt;
      $state = $this->selectRequiredResourcesAt($at, $now);
      if ($state !== AssetHoldPreparationState::READY) { return 0; }
      $this->waitingAt = null;
      $this->didResumeWait = true;
      $this->lookaheadAt = null;
      $this->scanAgainAfter = null;
      return max(0, $at - $current);
    }
    $safe = $current;
    $until = $current + max(0, $seconds);
    $extra = array_values(array_filter($decisionTimes, static fn(float $at): bool => $at > $current && $at <= $until));
    $times = array_merge($this->playback->getResourceSampleTimes($until), $extra);
    sort($times, SORT_NUMERIC);
    foreach (array_unique($times, SORT_REGULAR) as $at) {
      $state = $this->selectRequiredResourcesAt($at, $now);
      if ($state !== AssetHoldPreparationState::READY) {
        $this->waitingAt = $at;
        return max(0, $safe - $current);
      }
      if ($this->lookaheadAt !== null && $at >= $this->lookaheadAt) {
        $this->lookaheadAt = null;
        $this->scanAgainAfter = null;
      }
      $safe = $at;
      if ($this->playback->requiresResourceDecisionAt($at) || in_array($at, $extra, true)) {
        // Let the owning cue/result path commit before sampling its newly known presentation state.
        return max(0, $at - $current);
      }
    }
    return max(0, $seconds);
  }

  /** The shared scene drawing gate keeps the last resident frame while still transmitting pending resource declarations. */
  public function canPresentAt(float $now): bool
  {
    if (!$this->initialReady) { return $this->prepareInitialAt($now) === AssetHoldPreparationState::READY; }
    $this->syncMotionMode();
    $at = $this->waitingAt ?? $this->playback->elapsedSeconds;
    return $this->selectRequiredResourcesAt($at, $now) === AssetHoldPreparationState::READY;
  }

  /** Actual resolution can change return roles or begin result effects. No outcome is predicted for preparation. */
  public function refreshReturnsAt(float $now): void
  {
    $this->returns = ($this->collectReturns)();
    $this->refreshScaledField();
    $this->lookaheadAt = $this->scanAgainAfter = null;
    $this->window->clearPending();
    $state = $this->selectRequiredResourcesAt($this->playback->elapsedSeconds, $now);
    $this->waitingAt = $state === AssetHoldPreparationState::READY ? null : $this->playback->elapsedSeconds;
  }

  /** Start one incoming cohort early. Its readiness never blocks a still-resident current picture. */
  public function afterAdvanceAt(float $now): void
  {
    if (!$this->initialReady || $this->waitingAt !== null || $this->playback->isPaused || $this->playback->isCompleted) { return; }
    $this->syncMotionMode();
    if ($this->waitingAt !== null) { return; }
    $current = $this->playback->elapsedSeconds;
    if ($this->lookaheadAt !== null) {
      $this->window->prefetchResources($this->resourcesAt($this->lookaheadAt), $now, ($this->sharedRequests)());
      return;
    }
    if ($this->scanAgainAfter !== null && $current < $this->scanAgainAfter) { return; }
    $resources = $this->resourcesAt($current);
    $until = max($current, $this->playback->getDurationSeconds());
    foreach ($this->playback->getResourceSampleTimes($until) as $at) {
      if ($at <= $this->firstImageSeconds) { continue; }
      $incoming = $this->resourcesAt($at);
      if ($incoming != $resources) {
        $this->lookaheadAt = $at;
        $this->window->prefetchResources($incoming, $now, ($this->sharedRequests)());
        return;
      }
      if ($this->playback->requiresResourceDecisionAt($at)) { $this->scanAgainAfter = $at; return; }
    }
    $this->scanAgainAfter = $until;
  }

  private function selectRequiredResourcesAt(float $seconds, float $now): AssetHoldPreparationState
  {
    $resources = $this->resourcesAt($seconds);
    $state = $this->window->selectResources($resources, $now, ($this->sharedRequests)());
    if ($state === AssetHoldPreparationState::READY || $state === AssetHoldPreparationState::WAITING) { return $state; }
    throw new RendererTransportException('Battle command image window at playback ' . $seconds . 's '
      . $this->window->describeSelectionFailure($resources, $state) . '; playback remains held.');
  }

  private function syncMotionMode(): void
  {
    $mode = ($this->prefersReducedMotion)();
    if ($mode === $this->reducedMotion) { return; }
    $this->reducedMotion = $mode;
    $this->lookaheadAt = $this->scanAgainAfter = null;
    $this->window->clearPending();
    $this->returns = ($this->collectReturns)();
    $this->refreshScaledField();
    $this->findFirstImages();
    if ($this->initialReady) { $this->waitingAt = $this->playback->elapsedSeconds; }
  }

  private function findFirstImages(): void
  {
    $this->firstImages = [];
    $this->firstImageSeconds = 0;
    for ($frame = 0; $frame < $this->playback->session->totalFrames; $frame++) {
      $images = BattleEffectResources::collectCommandFrameResources($this->playback, $this->assetRoot, $frame, $this->reducedMotion);
      if ($images !== []) {
        $this->firstImages = $images;
        $this->firstImageSeconds = $this->playback->getElapsedSecondsAtFrame($frame);
        break;
      }
    }
  }

  private function refreshScaledField(): void
  {
    if ($this->collectScaledField === null) { return; }
    $scales = array_filter(BattleFieldMotion::getScaledFrames($this->playback, $this->reducedMotion),
      fn(int $frame): bool => $frame >= $this->playback->session->currentFrame, ARRAY_FILTER_USE_KEY);
    $this->lastScaledFrame = array_key_last($scales);
    $this->scaledField = $scales === [] ? [] : ($this->collectScaledField)($scales);
  }

  private function resourcesAt(float $seconds): array
  {
    $sampleAt = $seconds < $this->firstImageSeconds ? $this->firstImageSeconds : $seconds;
    $scaledField = $this->lastScaledFrame !== null
      && $seconds < $this->playback->getElapsedSecondsAtFrame($this->lastScaledFrame + 1) ? $this->scaledField : [];
    return array_replace($this->returns, $scaledField,
      BattleEffectResources::collectCommandResourcesAt($this->playback, $this->assetRoot, $sampleAt, $this->reducedMotion));
  }

  public function release(): void { $this->window->release(); }
  public function getCurrentRequests(): array { return $this->window->getCurrentRequests(); }
  public function getPendingRequests(): array { return $this->window->getPendingRequests(); }
}
