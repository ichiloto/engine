<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

/** Bounded ordered feedback; current renderers coalesce waiting attempts while older ordered transitions remain readable. */
final readonly class AssetHoldFeedback
{
  /** @var list<AssetHoldStatus> */
  public array $statuses;

  /** @param list<AssetHoldStatus> $statuses */
  public function __construct(array $statuses, public AssetHoldCapacity $capacity)
  {
    if (!array_is_list($statuses) || array_any($statuses, static fn($status) => !$status instanceof AssetHoldStatus)) {
      AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Hold statuses must be a list of typed results.');
    }
    if (count($statuses) > AssetHoldValidation::MAX_LEASES) {
      AssetHoldValidation::reject(AssetHoldRejection::LEASE_LIMIT, 'Feedback exceeds 1024 status entries.');
    }
    $this->statuses = $statuses;
  }

  public static function fromArray(array $value): self
  {
    AssetHoldValidation::assertKeys($value, ['holds', 'holdCapacity']);
    if (!is_array($value['holds']) || !array_is_list($value['holds'])) {
      AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Hold feedback requires a list of statuses.');
    }
    if (count($value['holds']) > AssetHoldValidation::MAX_LEASES) {
      AssetHoldValidation::reject(AssetHoldRejection::LEASE_LIMIT, 'Feedback exceeds 1024 status entries.');
    }
    return new self(array_map(static fn($status) => AssetHoldStatus::fromArray(
      AssetHoldValidation::readObject($status, AssetHoldRejection::SCHEMA)), $value['holds']),
      AssetHoldCapacity::fromArray(AssetHoldValidation::readObject($value['holdCapacity'], AssetHoldRejection::SCHEMA)));
  }
}
