<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use InvalidArgumentException;
use LogicException;

/** Current and incoming image cohorts. The outgoing cohort lives until its replacement is ready. */
final class AssetHoldWindow
{
  private ?AssetHoldPreparation $current = null;
  private ?AssetHoldPreparation $pending = null;
  /** @var array<string, AssetHoldResource> */
  private array $currentResources = [];
  /** @var array<string, AssetHoldResource> */
  private array $pendingResources = [];
  private bool $pendingIsPrefetch = false;
  private ?AssetHoldPreparationState $declinedPrefetch = null;
  private ?float $lastPoll = null;
  private bool $closed = false;

  public function __construct(private readonly AssetHoldLeases $leases, ?AssetHoldPreparation $initial = null)
  {
    if ($initial !== null && (!$initial->scope->usesLeases($leases) || $initial->deadline !== null)) {
      throw new InvalidArgumentException('An asset window adopts a feedback-only preparation from the same lease owner.');
    }
    $this->pending = $initial;
    $this->pendingResources = self::indexResources(array_map(
      static fn(AssetHoldRequest $request): AssetHoldResource => $request->resource, $initial?->requests ?? []));
  }

  /** Readiness is a barrier, never an elapsed-time fallback. Exact live requests are shared across the handoff.
   * @param array<string|int, AssetHoldResource> $resources
   * @param list<AssetHoldRequest> $sharedRequests Scene or other independently owned live requests.
   */
  public function selectResources(array $resources, float $now, array $sharedRequests = []): AssetHoldPreparationState
  {
    return $this->prepareResources($resources, $now, $sharedRequests, true);
  }

  /** Describe the matching required cohort while its refusal evidence is still held.
   * @param array<string|int, AssetHoldResource> $resources
   */
  public function describeSelectionFailure(array $resources, AssetHoldPreparationState $state): string
  {
    $resources = self::indexResources($resources);
    if ($this->current !== null && $resources == $this->currentResources) {
      return $this->current->describeFailure($state);
    }
    return $resources == $this->pendingResources
      ? ($this->pending?->describeFailure($state) ?? $state->value)
      : $state->value;
  }

  /** Prepare the incoming cohort while leaving the currently displayed cohort active.
   * @param array<string|int, AssetHoldResource> $resources @param list<AssetHoldRequest> $sharedRequests
   */
  public function prefetchResources(array $resources, float $now, array $sharedRequests = []): AssetHoldPreparationState
  {
    return $this->prepareResources($resources, $now, $sharedRequests, false);
  }

  /** @param array<string|int, AssetHoldResource> $resources @param list<AssetHoldRequest> $sharedRequests */
  private function prepareResources(array $resources, float $now, array $sharedRequests, bool $activate): AssetHoldPreparationState
  {
    if ($this->closed) { throw new LogicException('A released asset window cannot select resources.'); }
    if (!is_finite($now) || ($this->lastPoll !== null && $now < $this->lastPoll)) {
      throw new InvalidArgumentException('Asset windows require a finite nondecreasing clock.');
    }
    $resources = self::indexResources($resources);
    $this->lastPoll = $now;
    // Repainting the current cohort also observes any refusal of the next one.
    if ($this->pendingIsPrefetch && $this->pending !== null) { $this->pollPendingAt($this->pending, $now); }
    if ($this->current !== null && $this->currentResources == $resources) {
      // Repainting the current cohort must not cancel an independently preparing lookahead.
      return self::readState($this->current);
    }
    if ($this->pendingResources != $resources || ($activate && $this->declinedPrefetch !== null)) {
      $this->clearPending();
    }
    if ($this->declinedPrefetch !== null) { return $this->declinedPrefetch; }
    if ($this->pending === null) {
      $this->pending = new AssetHoldPreparation($this->leases, $resources, $now, null,
        [...$sharedRequests, ...($this->current?->scope->getRequests() ?? [])]);
      $this->pendingResources = $resources;
      $this->pendingIsPrefetch = !$activate;
    }
    if ($activate) { $this->pendingIsPrefetch = false; }
    $state = $this->pollPendingAt($this->pending, $now);
    if ($state !== AssetHoldPreparationState::READY || !$activate) { return $state; }
    $outgoing = $this->current;
    $this->current = $this->pending;
    $this->currentResources = $this->pendingResources;
    $this->pending = null;
    $this->pendingResources = [];
    $outgoing?->release();
    return self::readState($this->current);
  }

  /** Refused speculation releases the entire cohort. Remember its outcome until demand changes. */
  private function pollPendingAt(AssetHoldPreparation $pending, float $now): AssetHoldPreparationState
  {
    $pending->pollAt($now);
    $state = self::readState($pending);
    if ($this->pendingIsPrefetch && $state !== AssetHoldPreparationState::WAITING
      && $state !== AssetHoldPreparationState::READY) {
      $pending->release();
      $this->pending = null;
      $this->pendingIsPrefetch = false;
      $this->declinedPrefetch = $state;
    }
    return $state;
  }

  /** Current feedback is re-read, including a changed-file attempt after initial readiness. */
  private static function readState(AssetHoldPreparation $preparation): AssetHoldPreparationState
  {
    if ($preparation->state === AssetHoldPreparationState::CANCELLED) { return AssetHoldPreparationState::CANCELLED; }
    if ($preparation->scope->getRequests() !== $preparation->requests) { return AssetHoldPreparationState::SUPERSEDED; }
    return match ($preparation->scope->getReadiness()) {
      AssetHoldReadiness::READY => AssetHoldPreparationState::READY,
      AssetHoldReadiness::WAITING => AssetHoldPreparationState::WAITING,
      AssetHoldReadiness::REFUSED => AssetHoldPreparationState::REFUSED,
      AssetHoldReadiness::UNSUPPORTED => AssetHoldPreparationState::UNSUPPORTED,
    };
  }

  /** @return list<AssetHoldRequest> */
  public function getCurrentRequests(): array { return $this->current?->scope->getRequests() ?? []; }
  /** @return list<AssetHoldRequest> */
  public function getPendingRequests(): array { return $this->pending?->scope->getRequests() ?? []; }

  /** A changed semantic plan retires only its speculative incoming cohort. */
  public function clearPending(): void
  {
    $this->pending?->release();
    $this->pending = null;
    $this->pendingResources = [];
    $this->pendingIsPrefetch = false;
    $this->declinedPrefetch = null;
  }

  /** Cancellation, completion and shutdown retire both cohorts through the same owner path. */
  public function release(): void
  {
    $this->clearPending();
    $this->current?->release();
    $this->current = null;
    $this->currentResources = [];
    $this->closed = true;
  }

  /** @param array<string|int, AssetHoldResource> $resources @return array<string, AssetHoldResource> */
  private static function indexResources(array $resources): array
  {
    $indexed = [];
    foreach ($resources as $resource) {
      if (!$resource instanceof AssetHoldResource) {
        AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Asset window resources must be typed preparations.');
      }
      $indexed[$resource->getKey()] = $resource;
    }
    ksort($indexed);
    return $indexed;
  }
}
