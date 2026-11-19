<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

/** A presentation owner's resource lifetime, independent of the initial readiness decision. */
final class AssetHoldScope
{
  private bool $closed = false;
  /** @param array<string|int, AssetHoldResource> $resources
   * @param list<AssetHoldRequest> $sharedRequests Existing preparations promoted by the previous owner.
   */
  public function __construct(private readonly AssetHoldLeases $leases, array $resources, array $sharedRequests = [])
  {
    $leases->replaceResources($this, $resources, $sharedRequests);
  }

  public function usesLeases(AssetHoldLeases $leases): bool { return $this->leases === $leases; }

  /** @param array<string|int, AssetHoldResource> $resources */
  public function replaceResources(array $resources): void
  {
    $this->assertOpen();
    $this->leases->replaceResources($this, $resources);
  }

  /** Add newly known resources atomically while retaining earlier preparations needed by the same presentation.
   * @param array<string|int, AssetHoldResource> $resources
   */
  public function mergeResources(array $resources): void
  {
    $this->assertOpen();
    $this->leases->mergeResources($this, $resources);
  }

  /** @return list<AssetHoldRequest> */
  public function getRequests(): array { return $this->leases->getOwnedRequests($this); }

  public function getReadiness(): AssetHoldReadiness { return $this->leases->getReadiness($this); }

  /** @return list<AssetHoldStatus> */
  public function getStatuses(): array { return $this->leases->getStatuses($this); }

  public function release(): void
  {
    $this->closed = true;
    $this->leases->releaseOwner($this);
  }

  private function assertOpen(): void
  {
    if ($this->closed) { throw new \LogicException('A released asset scope cannot acquire resources.'); }
  }
}
