<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use InvalidArgumentException;

/** One initial readiness decision; the host selects a deadline or feedback-only wait. Its scope owns playback resources. */
final class AssetHoldPreparation
{
  /** @var list<AssetHoldRequest> Initial readiness snapshot, independent of later outcome resources. */
  public readonly array $requests;
  public readonly AssetHoldScope $scope;
  public readonly ?float $deadline;
  public private(set) AssetHoldPreparationState $state = AssetHoldPreparationState::WAITING;
  private float $lastPoll;

  /** @param array<string|int, AssetHoldResource> $resources
   * @param list<AssetHoldRequest> $sharedRequests Existing live requests whose residency carries into this scope.
   * The host supplies the shared monotonic clock; null timeout waits for readiness or explicit failure.
   */
  public function __construct(AssetHoldLeases $leases, array $resources,
    float $startedAt, ?float $timeoutSeconds, array $sharedRequests = [])
  {
    if (!is_finite($startedAt) || ($timeoutSeconds !== null && (!is_finite($timeoutSeconds)
      || $timeoutSeconds <= 0 || !is_finite($startedAt + $timeoutSeconds)))) {
      throw new InvalidArgumentException('Resource preparation requires a finite start and null or a positive finite timeout.');
    }
    $this->deadline = $timeoutSeconds === null ? null : $startedAt + $timeoutSeconds;
    $this->lastPoll = $startedAt;
    $this->scope = new AssetHoldScope($leases, $resources, $sharedRequests);
    $this->requests = $this->scope->getRequests();
  }

  public function pollAt(float $now): AssetHoldPreparationState
  {
    if (!is_finite($now) || $now < $this->lastPoll) {
      throw new InvalidArgumentException('Resource preparation polls require finite nondecreasing seconds.');
    }
    $this->lastPoll = $now;
    if ($this->state !== AssetHoldPreparationState::WAITING) { return $this->state; }
    if ($this->scope->getRequests() !== $this->requests) {
      return $this->state = AssetHoldPreparationState::SUPERSEDED;
    }
    $readiness = $this->scope->getReadiness();
    return $this->state = match ($readiness) {
      AssetHoldReadiness::READY => AssetHoldPreparationState::READY,
      AssetHoldReadiness::REFUSED => AssetHoldPreparationState::REFUSED,
      AssetHoldReadiness::UNSUPPORTED => AssetHoldPreparationState::UNSUPPORTED,
      AssetHoldReadiness::WAITING => $this->deadline !== null && $now >= $this->deadline
        ? AssetHoldPreparationState::TIMED_OUT : AssetHoldPreparationState::WAITING,
    };
  }

  /** Completion keeps resources held until their presentation owner explicitly ends the scope. */
  public function release(): void
  {
    $this->scope->release();
    $this->state = AssetHoldPreparationState::CANCELLED;
  }

  /** Describe the renderer's refusal for this exact request revision, before its owner releases it. */
  public function describeFailure(AssetHoldPreparationState $state): string
  {
    if ($state !== AssetHoldPreparationState::REFUSED) { return $state->value; }
    $status = array_find($this->getStatuses(), static fn(AssetHoldStatus $status): bool =>
      $status->state === AssetHoldState::REFUSED);
    if ($status === null) { return $state->value; }

    $request = array_find($this->requests, static fn(AssetHoldRequest $request): bool =>
      $request->id === $status->id && $request->revision === $status->revision);
    $rect = $request?->resource->sourceRect;
    $detail = $state->value . ': ' . ($request?->resource->asset ?? $status->id);
    if ($rect !== null) { $detail .= " ({$rect->x},{$rect->y} {$rect->width}x{$rect->height})"; }
    $detail .= ' [' . $status->reason->value . ']';
    if ($status->baseBytes !== null && $status->regionBytes !== null) {
      $detail .= " (base {$status->baseBytes} bytes, region {$status->regionBytes} bytes)";
    }
    if ($status->message !== null && $status->message !== '') { $detail .= ': ' . $status->message; }
    return $detail;
  }

  /** @return list<AssetHoldStatus> */
  public function getStatuses(): array { return $this->scope->getStatuses(); }
}
