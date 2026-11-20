<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use LogicException;
use OverflowException;
use WeakMap;

/** One presentation session. Scene owners select resources; this class owns request and reply lifetimes. */
final class AssetHoldLeases
{
  /** Revisions are process-local and never reused, including across presentation sessions. */
  private static int $revision = 0;
  /** @var WeakMap<object, array<string|int, AssetHoldRequest>> */
  private WeakMap $owners;
  /** @var array<string, AssetHoldStatus> */
  private array $statuses = [];
  private ?bool $supported = null;
  public private(set) ?AssetHoldCapacity $capacity = null;

  public function __construct() { $this->owners = new WeakMap(); }

  /** Replace one owner's complete set atomically; another owner's leases cannot be removed here.
   * @param array<string|int, CanvasImage> $images Stable owner-local roles mapped to actual image preparations.
   * @return list<AssetHoldRequest>
   */
  public function replaceImages(object $owner, array $images): array
  {
    $resources = [];
    foreach ($images as $role => $image) {
      if (!$image instanceof CanvasImage) {
        AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Owner resources must be canvas images.');
      }
      $resources[$role] = AssetHoldResource::fromCanvasImage($image);
    }
    return $this->replaceResources($owner, $resources);
  }

  /** @param array<string|int, AssetHoldResource> $resources
   * @param list<AssetHoldRequest> $sharedRequests Explicitly shared live preparations; ordinary owners remain independent.
   * @return list<AssetHoldRequest>
   */
  public function replaceResources(object $owner, array $resources, array $sharedRequests = []): array
  {
    foreach ($resources as $role => $resource) {
      AssetHoldValidation::readId((string)$role);
      if (!$resource instanceof AssetHoldResource) {
        AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Owner resources must be typed pixel preparations.');
      }
    }
    if (!array_is_list($sharedRequests)) { AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Shared requests must be a list.'); }
    $live = $this->collectRequests();
    $shared = [];
    foreach ($sharedRequests as $request) {
      if (!$request instanceof AssetHoldRequest || ($live[$request->id] ?? null) !== $request) {
        throw new LogicException('Resource ownership may share only a currently owned request.');
      }
      $shared[$request->resource->getKey()] = $request;
    }
    $previous = $this->owners[$owner] ?? [];
    foreach ($resources as $role => $resource) {
      $request = $shared[$resource->getKey()] ?? null;
      if ($request !== null && $request->resource == $resource
        && (!isset($previous[$role]) || $previous[$role]->resource != $resource)) {
        $previous[$role] = $request;
      }
    }
    $kept = $this->collectRequests($owner);
    $changed = 0;
    foreach ($resources as $role => $resource) {
      if (!isset($previous[$role]) || $previous[$role]->resource != $resource) { $changed++; }
      else { $kept[$previous[$role]->id] = $previous[$role]; }
    }
    self::assertLeaseCapacity(count($kept) + $changed);
    self::assertRevisionCapacity($changed);
    $next = [];
    $lastRevision = self::$revision;
    foreach ($resources as $role => $resource) {
      $before = $previous[$role] ?? null;
      if ($before !== null && $before->resource == $resource) {
        $next[$role] = $before;
      } else {
        $revision = ++$lastRevision;
        $next[$role] = new AssetHoldRequest('resource-lease-' . $revision, $revision, $resource);
      }
    }
    $desired = $kept;
    foreach ($next as $request) { $desired[$request->id] = $request; }
    self::assertResetBudget($desired);
    self::$revision = $lastRevision;
    if ($next === []) { unset($this->owners[$owner]); }
    else { $this->owners[$owner] = $next; }
    $this->getDesiredRequests();
    return array_values($next);
  }

  /** Extend one owner's intent after new presentation facts become known; shared admission remains atomic.
   * @param array<string|int, AssetHoldResource> $resources @return list<AssetHoldRequest>
   */
  public function mergeResources(object $owner, array $resources): array
  {
    $current = array_map(static fn(AssetHoldRequest $request): AssetHoldResource => $request->resource,
      $this->owners[$owner] ?? []);
    return $this->replaceResources($owner, array_replace($current, $resources));
  }

  public function rereadResource(object $owner, string $role): AssetHoldRequest
  {
    $requests = $this->owners[$owner] ?? [];
    $before = $requests[$role] ?? throw new LogicException('Cannot refresh an unowned asset lease.');
    $kept = $this->collectRequests($owner);
    foreach ($requests as $key => $request) {
      if ((string)$key !== $role) { $kept[$request->id] = $request; }
    }
    self::assertLeaseCapacity(count($kept) + 1);
    self::assertRevisionCapacity(1);
    $revision = self::$revision + 1;
    $next = new AssetHoldRequest('resource-lease-' . $revision, $revision, $before->resource, reread: true);
    $kept[$next->id] = $next;
    self::assertResetBudget($kept);
    self::$revision = $revision;
    $requests[$role] = $next;
    $this->owners[$owner] = $requests;
    $this->getDesiredRequests();
    return $next;
  }

  public function releaseOwner(object $owner): void
  {
    unset($this->owners[$owner]);
    $this->getDesiredRequests();
  }

  /** A frozen-frame owner shares existing immutable requests instead of inventing new wire leases.
   * Borrow before the producer releases its resources; a stale frame cannot revive a retired request.
   * @param list<AssetHoldRequest> $requests
   */
  public function borrowRequests(object $owner, array $requests): void
  {
    if (!array_is_list($requests)) { AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Borrowed requests must be a list.'); }
    $current = $this->collectRequests();
    $next = [];
    foreach ($requests as $request) {
      if (!$request instanceof AssetHoldRequest || ($current[$request->id] ?? null) !== $request) {
        throw new LogicException('A frozen frame may borrow only a currently owned request.');
      }
      $next[$request->id] = $request;
    }
    if ($next === []) { unset($this->owners[$owner]); }
    else { $this->owners[$owner] = $next; }
    $this->getDesiredRequests();
  }

  /** @return list<AssetHoldRequest> */
  public function getDesiredRequests(?object $exceptOwner = null): array
  {
    $requests = $this->collectRequests();
    // A forgotten owner cannot leak readiness state after its weak ownership has ended.
    $this->statuses = array_intersect_key($this->statuses, $requests);
    return array_values($exceptOwner === null ? $requests : $this->collectRequests($exceptOwner));
  }

  /** @return list<AssetHoldRequest> */
  public function getOwnedRequests(object $owner): array { return array_values($this->owners[$owner] ?? []); }

  /** A reconnect keeps desired owners/revisions, but residency must be acknowledged by the new process. */
  public function startSession(bool $supported): void
  {
    $this->supported = $supported;
    $this->statuses = [];
    $this->capacity = null;
  }

  public function endSession(): void
  {
    $this->supported = null;
    $this->statuses = [];
    $this->capacity = null;
  }

  public function getReadiness(object $owner): AssetHoldReadiness
  {
    $requests = $this->owners[$owner] ?? [];
    if ($requests === []) { return AssetHoldReadiness::READY; }
    if ($this->supported === false) { return AssetHoldReadiness::UNSUPPORTED; }
    $waiting = $this->supported === null;
    foreach ($requests as $request) {
      $status = $this->statuses[$request->id] ?? null;
      if ($status?->state === AssetHoldState::REFUSED) { return AssetHoldReadiness::REFUSED; }
      if ($status?->state !== AssetHoldState::HELD) { $waiting = true; }
    }
    return $waiting ? AssetHoldReadiness::WAITING : AssetHoldReadiness::READY;
  }

  /** @return list<AssetHoldStatus> */
  public function getStatuses(object $owner): array
  {
    $results = [];
    foreach ($this->owners[$owner] ?? [] as $request) {
      if (isset($this->statuses[$request->id])) { $results[] = $this->statuses[$request->id]; }
    }
    return $results;
  }

  /** Resource results are delivered in wire order before ordinary ACK progress is coalesced. */
  public function applyFeedback(AssetHoldFeedback $feedback): void
  {
    if ($this->supported !== true) { throw new LogicException('Asset feedback requires a subscribed live session.'); }
    $desired = [];
    foreach ($this->getDesiredRequests() as $request) { $desired[$request->id] = $request; }
    $next = $this->statuses;
    foreach ($feedback->statuses as $incoming) {
      if (($desired[$incoming->id]->revision ?? null) !== $incoming->revision) { continue; }
      $before = $next[$incoming->id] ?? null;
      if ($before !== null && $incoming->attempt < $before->attempt) { continue; }
      if ($before !== null && $incoming->attempt === $before->attempt) {
        if ($before == $incoming || $before->state === AssetHoldState::ENDED) { continue; }
        // A delayed pending snapshot cannot undo a completed asynchronous result.
        if ($incoming->state === AssetHoldState::PENDING && $before->state !== AssetHoldState::PENDING) { continue; }
        if ($before->state === AssetHoldState::REFUSED || $before->state === $incoming->state
          || ($before->state === AssetHoldState::HELD && $incoming->state !== AssetHoldState::ENDED)) {
          throw new LogicException('Contradictory asset hold transition for one request attempt.');
        }
      }
      $next[$incoming->id] = $incoming;
    }
    $this->statuses = $next;
    $this->capacity = $feedback->capacity;
  }

  /** Validate a complete incoming batch before the client commits queues or resource state.
   * @param list<AssetHoldFeedback> $feedbacks
   */
  public function assertFeedbacks(array $feedbacks, ?bool $startSession = null): void
  {
    $trial = clone $this;
    if ($startSession !== null) { $trial->startSession($startSession); }
    foreach ($feedbacks as $feedback) { $trial->applyFeedback($feedback); }
  }

  private static function assertRevisionCapacity(int $count): void
  {
    if ($count > AssetHoldValidation::MAX_REVISION - self::$revision) {
      throw new OverflowException('Asset lease revision space is exhausted.');
    }
  }

  /** @return array<string, AssetHoldRequest> */
  private function collectRequests(?object $exceptOwner = null): array
  {
    $requests = [];
    foreach ($this->owners as $owner => $owned) {
      if ($owner === $exceptOwner) { continue; }
      foreach ($owned as $request) { $requests[$request->id] = $request; }
    }
    return $requests;
  }

  /** Every desired set must fit an atomic reset, including currently borrowed frozen-frame requests.
   * @param array<string, AssetHoldRequest> $requests
   */
  private static function assertResetBudget(array $requests): void
  {
    AssetHoldWireSize::assertRequestsFitReset($requests);
  }

  private static function assertLeaseCapacity(int $count): void
  {
    if ($count > AssetHoldValidation::MAX_LEASES) {
      AssetHoldValidation::reject(AssetHoldRejection::LEASE_LIMIT, 'Desired set exceeds 1024 unique leases.');
    }
  }
}
