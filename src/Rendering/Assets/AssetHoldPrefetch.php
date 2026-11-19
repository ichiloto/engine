<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Ichiloto\Engine\Util\Debug;

/** One semantic selection, retained through its queued-command handoff until promotion or explicit retirement. */
final class AssetHoldPrefetch
{
  private ?string $selection = null;

  public function __construct(private readonly AssetHoldLeases $leases) {}

  public function usesLeases(AssetHoldLeases $leases): bool { return $this->leases === $leases; }

  /** Collect once for a new semantic choice; refusal is retried only after the choice changes or an explicit clear.
   * @param Closure(): array<string|int, AssetHoldResource> $collect
   * @param list<AssetHoldRequest> $sharedRequests Actual scene preparations already owned by the same session.
   */
  public function selectResources(string $selection, Closure $collect, array $sharedRequests = []): void
  {
    AssetHoldValidation::readId($selection);
    $this->poll();
    if ($this->selection === $selection) { return; }
    $this->clear();
    $this->selection = $selection;
    try { $this->leases->replaceResources($this, $collect(), $sharedRequests); $this->poll(); }
    catch (InvalidArgumentException|RuntimeException $failure) {
      $this->leases->releaseOwner($this);
      Debug::warn('Focused asset prefetch unavailable: ' . $failure->getMessage());
    }
  }

  /** Refused speculation owns neither a wire slot nor pinned capacity after this update. */
  public function poll(): void
  {
    $readiness = $this->leases->getReadiness($this);
    if ($readiness === AssetHoldReadiness::REFUSED || $readiness === AssetHoldReadiness::UNSUPPORTED) {
      $assets = array_map(static fn(AssetHoldRequest $request): string => $request->resource->asset,
        $this->leases->getOwnedRequests($this));
      $this->leases->releaseOwner($this);
      Debug::warn('Focused asset prefetch ' . strtolower($readiness->name) . ': ' . implode(', ', array_slice($assets, 0, 3)));
    }
  }

  /** Keep only exact selected preparations before admission, then share their immutable requests into the command.
   * @param array<string|int, AssetHoldResource> $resources
   * @param list<AssetHoldRequest> $sharedRequests Actual scene preparations kept independently of selection.
   */
  public function prepareResources(array $resources, float $startedAt, ?float $timeoutSeconds, array $sharedRequests = []): AssetHoldPreparation
  {
    $this->poll();
    $selected = [];
    foreach ($resources as $resource) {
      if (!$resource instanceof AssetHoldResource) {
        AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Command resources must be typed preparations.');
      }
      $selected[$resource->getKey()] = $resource;
    }
    $shared = array_values(array_filter($this->leases->getOwnedRequests($this),
      static fn(AssetHoldRequest $request): bool => ($selected[$request->resource->getKey()] ?? null) == $request->resource));
    // Release competing speculative requests before the new command is charged or consumes revisions.
    $this->leases->borrowRequests($this, $shared);
    try { return new AssetHoldPreparation($this->leases, $resources, $startedAt, $timeoutSeconds, [...$sharedRequests, ...$shared]); }
    finally { $this->clear(); }
  }

  /** Selection change, unavailability, pause, cancellation, completion and shutdown use this same owner release. */
  public function clear(): void
  {
    $this->leases->releaseOwner($this);
    $this->selection = null;
  }

  /** @return list<AssetHoldRequest> */
  public function getRequests(): array { return $this->leases->getOwnedRequests($this); }
}
