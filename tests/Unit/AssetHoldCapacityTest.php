<?php

use Ichiloto\Engine\Rendering\Assets\AssetHoldCapacity;
use Ichiloto\Engine\Rendering\Assets\AssetHoldRejection;
use Ichiloto\Engine\Rendering\Assets\AssetHoldValidation;

it('reads the shared unified ledger observations in array and object feedback', function (array $value, bool $object) {
  if ($object) { $value = (array)json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR); }
  $capacity = AssetHoldCapacity::fromArray($value);
  expect($capacity->bytes)->toBe($value['bytes'])->and($capacity->count)->toBe($value['count']);
  $owners = [$capacity->held, $capacity->foreground, $capacity->staging, $capacity->cache];
  expect(array_sum(array_map(static fn($owner) => $owner->bytes, $owners)))->toBe($capacity->bytes)
    ->and(array_sum(array_map(static fn($owner) => $owner->count, $owners)))->toBe($capacity->count)
    ->and($capacity->staging->limitBytes)->toBeGreaterThan(0)
    ->and($capacity->held->limitBytes)->toBeNull()->and($capacity->cache->limitBytes)->toBeNull();
})->with(function () {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-holds-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  foreach ($fixture['capacityCases']['valid'] as $case) {
    foreach ([false, true] as $object) { yield $case['name'] . ($object ? ' object' : ' array') => [$case['value'], $object]; }
  }
});

it('rejects malformed and inconsistent shared ledger observations before lease mutation', function (array $value, bool $object) {
  if ($object) { $value = (array)json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR); }
  expect(fn() => AssetHoldCapacity::fromArray($value))->toThrow(InvalidArgumentException::class, 'asset_hold schema:');
})->with(function () {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-holds-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  foreach ($fixture['capacityCases']['invalid'] as $case) {
    foreach ([false, true] as $object) { yield $case['name'] . ($object ? ' object' : ' array') => [$case['value'], $object]; }
  }
});
