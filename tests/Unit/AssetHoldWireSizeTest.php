<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Assets\{AssetHoldLeases, AssetHoldRequest, AssetHoldResource, AssetHoldWireSize};

it('matches canonical operation byte examples from the shared renderer fixture', function () {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-holds-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  foreach ($fixture['limits']['transaction']['examples'] as $example) {
    if ($example['operation']['op'] === 'put') {
      expect(AssetHoldRequest::readOperation($example['operation'])->serializedBytes)->toBe($example['bytes']);
    }
  }
});

it('counts canonical binary64 lengths against independently generated backend fractions', function () {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-hold-fraction-bytes.json'), true, flags: JSON_THROW_ON_ERROR);
  foreach ($fixture['cases'] as $sample) {
    $value = unpack('Evalue', hex2bin($sample['bits']))['value'];
    expect(AssetHoldWireSize::getFractionBytes($value))->toBe($sample['bytes']);
  }
  foreach ([NAN, INF, -1.0, 1.01] as $bad) { expect(fn() => AssetHoldWireSize::getFractionBytes($bad))->toThrow(LogicException::class); }
});

it('refuses metadata-heavy desired sets atomically before consuming revisions or changing existing owners', function () {
  $leases = new AssetHoldLeases(); $owner = new stdClass();
  $before = $leases->replaceResources($owner, ['initial' => new AssetHoldResource('fixtures/initial.png')]);
  $heavy = new AssetHoldResource('a/' . str_repeat("\x01", 4094));
  expect(fn() => $leases->replaceResources($owner, array_fill(0, 256, $heavy)))
    ->toThrow(InvalidArgumentException::class, 'asset_hold transaction_limit');
  expect($leases->getDesiredRequests())->toBe($before);
  $after = $leases->replaceResources($owner, ['initial' => new AssetHoldResource('fixtures/replacement.png')]);
  expect($after[0]->revision)->toBe($before[0]->revision + 1);
});

it('counts exactly the reset threshold and rejects one more encoded byte without a per-resource path restriction', function () {
  $resource = new AssetHoldResource('a/' . str_repeat("\x01", 4094));
  $requests = [];
  for ($i = 0; $i < 169; $i++) { $requests[] = new AssetHoldRequest('fill-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 1, $resource); }
  $a = new AssetHoldRequest('padding-a', 1, new AssetHoldResource('a/'));
  $b = new AssetHoldRequest('padding-b', 1, new AssetHoldResource('a/'));
  $gap = 4194304 - array_sum(array_column($requests, 'serializedBytes')) - $a->serializedBytes - $b->serializedBytes;
  $parts = [intdiv($gap, 2), $gap - intdiv($gap, 2)];
  foreach ($parts as $i => $bytes) {
    $asset = 'a/' . str_repeat("\x01", intdiv($bytes, 6)) . str_repeat('x', $bytes % 6);
    expect(strlen($asset))->toBeLessThanOrEqual(4096);
    $requests[] = new AssetHoldRequest($i === 0 ? 'padding-a' : 'padding-b', 1, new AssetHoldResource($asset));
  }
  expect(array_sum(array_column($requests, 'serializedBytes')))->toBe(4194304);
  AssetHoldWireSize::assertRequestsFitReset($requests);
  $last = $requests[array_key_last($requests)];
  $requests[array_key_last($requests)] = new AssetHoldRequest($last->id, $last->revision, new AssetHoldResource($last->resource->asset . 'x'));
  expect(array_sum(array_column($requests, 'serializedBytes')))->toBe(4194305)
    ->and(fn() => AssetHoldWireSize::assertRequestsFitReset($requests))->toThrow(InvalidArgumentException::class, 'transaction_limit');
});

it('counts logical drawing extents using canonical backend floating point notation', function () {
  foreach ([[1.0, '1.0'], [1.0000000000000002, '1.0000000000000002'], [271.4, '271.4'],
    [16384.0, '16384.0'], [1e-6, '1e-6'], [1e-5, '0.00001'], [0.0001, '0.0001'],
    [16383.999999999998, '16383.999999999998'], [-271.4, '-271.4']] as [$number, $text]) {
    expect(AssetHoldWireSize::getNumberBytes($number))->toBe(strlen($text));
  }
  foreach ([NAN, INF, -INF] as $number) {
    expect(fn() => AssetHoldWireSize::getNumberBytes($number))->toThrow(LogicException::class);
  }
});
