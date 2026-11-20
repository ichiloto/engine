<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

/** One image ledger; each allocation belongs to its strongest current owner exactly once. */
final readonly class AssetHoldCapacity
{
  public function __construct(public int $bytes, public int $limitBytes, public int $count, public int $limitCount,
    public AssetHoldOwnerUsage $held, public AssetHoldOwnerUsage $staging,
    public AssetHoldOwnerUsage $foreground, public AssetHoldOwnerUsage $cache)
  {
    AssetHoldValidation::readInteger($bytes, 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    AssetHoldValidation::readInteger($count, 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    AssetHoldValidation::readInteger($limitBytes, 1, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    AssetHoldValidation::readInteger($limitCount, 1, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    if ($staging->limitBytes === null || $held->limitBytes !== null
      || $foreground->limitBytes !== null || $cache->limitBytes !== null) {
      AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Only staging reports an owner byte sublimit.');
    }
    $sumBytes = $sumCount = 0;
    foreach ([$held, $foreground, $staging, $cache] as $owner) {
      if ($owner->bytes > PHP_INT_MAX - $sumBytes || $owner->count > PHP_INT_MAX - $sumCount) {
        AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Reported image ownership totals overflow.');
      }
      $sumBytes += $owner->bytes;
      $sumCount += $owner->count;
    }
    if ($sumBytes !== $bytes || $sumCount !== $count) {
      AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Reported image bytes and count must equal their four-owner sums.');
    }
  }

  public static function fromArray(array $value): self
  {
    AssetHoldValidation::assertKeys($value, ['bytes', 'limitBytes', 'count', 'limitCount', 'owners']);
    $owners = AssetHoldValidation::readObject($value['owners'], AssetHoldRejection::SCHEMA);
    AssetHoldValidation::assertKeys($owners, ['held', 'staging', 'foreground', 'cache']);
    $read = static fn(string $key, int $minimum): int => AssetHoldValidation::readInteger(
      $value[$key], $minimum, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    $owner = static fn(string $key, bool $withLimit = false): AssetHoldOwnerUsage => AssetHoldOwnerUsage::fromArray(
      AssetHoldValidation::readObject($owners[$key], AssetHoldRejection::SCHEMA), $withLimit);
    return new self($read('bytes', 0), $read('limitBytes', 1), $read('count', 0), $read('limitCount', 1),
      $owner('held'), $owner('staging', true), $owner('foreground'), $owner('cache'));
  }
}
