<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

/** A truthful allocation observation. A lowered ceiling may temporarily be below live usage. */
final readonly class AssetHoldOwnerUsage
{
  public function __construct(public int $bytes, public int $count, public ?int $limitBytes = null)
  {
    AssetHoldValidation::readInteger($bytes, 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    AssetHoldValidation::readInteger($count, 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    if ($limitBytes !== null) {
      AssetHoldValidation::readInteger($limitBytes, 1, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    }
  }

  public static function fromArray(array $value, bool $withLimit = false): self
  {
    AssetHoldValidation::assertKeys($value, $withLimit ? ['bytes', 'count', 'limitBytes'] : ['bytes', 'count']);
    return new self(
      AssetHoldValidation::readInteger($value['bytes'], 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA),
      AssetHoldValidation::readInteger($value['count'], 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA),
      $withLimit ? AssetHoldValidation::readInteger($value['limitBytes'], 1, PHP_INT_MAX, AssetHoldRejection::SCHEMA) : null,
    );
  }
}
