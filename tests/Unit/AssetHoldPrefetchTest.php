<?php

use Ichiloto\Engine\Rendering\Assets\{AssetHoldFeedback, AssetHoldLeases, AssetHoldPrefetch, AssetHoldPreparationState, AssetHoldResource};

beforeEach(function () {
  $this->leases = new AssetHoldLeases();
  $this->leases->startSession(true);
  $this->focus = new AssetHoldPrefetch($this->leases);
  $this->a = new AssetHoldResource('synthetic/a.png');
  $this->b = new AssetHoldResource('synthetic/b.png');
  $this->reply = static function ($request, string $state): AssetHoldFeedback {
    return AssetHoldFeedback::fromArray(['holds' => [['id' => $request->id, 'revision' => $request->revision,
      'attempt' => 1, 'state' => $state, 'regionBytes' => 4096, 'baseBytes' => 0,
      ...($state === 'refused' ? ['reason' => 'over_budget'] : [])]],
      'holdCapacity' => createTestAssetHoldCapacity(0, 0)]);
  };
});

it('prepares only the current semantic selection and retires its previous resources', function () {
  $calls = 0;
  $collect = function () use (&$calls): array { $calls++; return ['picture' => $this->a]; };
  $this->focus->selectResources('first', $collect);
  $first = $this->focus->getRequests()[0];
  $this->focus->selectResources('first', $collect);
  expect($calls)->toBe(1)->and($this->leases->getDesiredRequests())->toBe([$first]);
  $this->focus->selectResources('second', fn() => ['picture' => $this->b]);
  $second = $this->focus->getRequests()[0];
  $this->leases->applyFeedback(($this->reply)($first, 'held'));
  expect($this->leases->getDesiredRequests())->toBe([$second]);
  $this->focus->clear();
  expect($this->leases->getDesiredRequests())->toBe([]);
});

it('releases refused speculation without retrying the same selection or consuming a slot', function () {
  $calls = 0;
  $collect = function () use (&$calls): array { $calls++; return ['picture' => $this->a]; };
  $this->focus->selectResources('first', $collect);
  $first = $this->focus->getRequests()[0];
  $this->leases->applyFeedback(($this->reply)($first, 'refused'));
  $this->focus->selectResources('first', $collect);
  expect($this->leases->getDesiredRequests())->toBe([])->and($calls)->toBe(1);
  $this->focus->selectResources('different', $collect);
  expect($this->focus->getRequests())->toHaveCount(1)->and($calls)->toBe(2);
});

it('promotes exact ready requests and releases competitors before required admission at the session ceiling', function () {
  $other = new stdClass();
  $foreign = [];
  for ($index = 0; $index < 1022; $index++) { $foreign[$index] = new AssetHoldResource('foreign/' . $index . '.png'); }
  $foreignRequests = $this->leases->replaceResources($other, $foreign);
  $this->focus->selectResources('selected', fn() => ['keep' => $this->a, 'competitor' => $this->b]);
  [$kept, $discarded] = $this->focus->getRequests();
  $this->leases->applyFeedback(($this->reply)($kept, 'held'));
  $third = new AssetHoldResource('synthetic/required.png');
  $prepare = $this->focus->prepareResources(['keep' => $this->a, 'new' => $third], 10, null);
  expect($prepare->requests[0])->toBe($kept)
    ->and($prepare->requests[1]->resource)->toBe($third)
    ->and($this->focus->getRequests())->toBe([])
    ->and($this->leases->getDesiredRequests())->toHaveCount(1024)
    ->and($this->leases->getDesiredRequests())->not->toContain($discarded)
    ->and($prepare->pollAt(11))->toBe(AssetHoldPreparationState::WAITING);
  $this->leases->applyFeedback(($this->reply)($prepare->requests[1], 'held'));
  expect($prepare->pollAt(1000))->toBe(AssetHoldPreparationState::READY);
  $prepare->release();
  expect($this->leases->getDesiredRequests())->toBe($foreignRequests);
});

it('retires speculation if the required command itself exceeds admission without touching other owners', function () {
  $other = new stdClass(); $foreign = [];
  for ($index = 0; $index < 1024; $index++) { $foreign[$index] = new AssetHoldResource('foreign/' . $index . '.png'); }
  $kept = $this->leases->replaceResources($other, $foreign);
  $this->focus->selectResources('optional-too-large', fn() => ['picture' => $this->a]);
  expect($this->focus->getRequests())->toBe([]);
  expect(fn() => $this->focus->prepareResources(['required' => $this->a], 0, null))
    ->toThrow(InvalidArgumentException::class, 'asset_hold lease_limit');
  expect($this->leases->getDesiredRequests())->toBe($kept)->and($this->focus->getRequests())->toBe([]);
});

it('retires unsupported speculation immediately and clears resources for pause or shutdown', function () {
  $this->leases->startSession(false);
  $this->focus->selectResources('unsupported', fn() => ['picture' => $this->a]);
  expect($this->focus->getRequests())->toBe([])->and($this->leases->getDesiredRequests())->toBe([]);
  $this->leases->startSession(true);
  $this->focus->clear();
  $this->focus->selectResources('resumed', fn() => ['picture' => $this->a]);
  expect($this->focus->getRequests())->toHaveCount(1);
  $this->focus->clear();
  expect($this->leases->getDesiredRequests())->toBe([]);
});

it('refuses sharing a retired request before changing either owner', function () {
  $source = new stdClass(); $target = new stdClass();
  $old = $this->leases->replaceResources($source, ['picture' => $this->a])[0];
  $other = $this->leases->replaceResources($target, ['picture' => $this->b]);
  $this->leases->releaseOwner($source);
  expect(fn() => $this->leases->replaceResources($target, ['picture' => $this->a], [$old]))
    ->toThrow(LogicException::class, 'currently owned');
  expect($this->leases->getDesiredRequests())->toBe($other);
});


it('shares scene residency through focus and command disposal without making the scene depend on either owner', function () {
  $scene = new stdClass();
  $sceneRequests = $this->leases->replaceResources($scene, ['ordinary-hit' => $this->a]);
  $this->leases->applyFeedback(($this->reply)($sceneRequests[0], 'held'));
  $this->focus->selectResources('attack', fn() => ['effect' => $this->a], $sceneRequests);
  expect($this->focus->getRequests())->toBe($sceneRequests)->and($this->leases->getDesiredRequests())->toBe($sceneRequests);
  $prepare = $this->focus->prepareResources(['effect' => $this->a], 0, null, $sceneRequests);
  expect($prepare->requests)->toBe($sceneRequests)->and($prepare->pollAt(0))->toBe(AssetHoldPreparationState::READY);
  $prepare->release();
  $this->focus->clear();
  expect($this->leases->getDesiredRequests())->toBe($sceneRequests);
});
