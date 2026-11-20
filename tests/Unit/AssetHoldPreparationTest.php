<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback;
use Ichiloto\Engine\Rendering\Assets\AssetHoldLeases;
use Ichiloto\Engine\Rendering\Assets\AssetHoldPreparation;
use Ichiloto\Engine\Rendering\Assets\AssetHoldPreparationState;
use Ichiloto\Engine\Rendering\Assets\AssetHoldResource;

beforeEach(function () {
  $this->leases = new AssetHoldLeases();
  $this->leases->startSession(true);
  $this->resource = new AssetHoldResource('fixtures/replaceable.png');
  $this->prepare = new AssetHoldPreparation($this->leases, ['return' => $this->resource], 10.0, 2.0);
  $this->feedback = function (string $state, int $attempt = 1): AssetHoldFeedback {
    $r = $this->prepare->requests[0];
    return AssetHoldFeedback::fromArray(['holds' => [[
      'id' => $r->id, 'revision' => $r->revision, 'attempt' => $attempt, 'state' => $state,
      ...($state === 'ended' ? ['reason' => 'changed'] : ['regionBytes' => 4096, 'baseBytes' => 0]),
      ...($state === 'refused' ? ['reason' => 'over_budget'] : []),
    ]], 'holdCapacity' => createTestAssetHoldCapacity(4096, 1)]);
  };
});

it('stays pending without advancing any clock and ends the wait at its exact deadline', function () {
  expect($this->prepare->pollAt(10.0))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->prepare->pollAt(11.999))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->prepare->pollAt(12.0))->toBe(AssetHoldPreparationState::TIMED_OUT)
    ->and($this->prepare->pollAt(50.0))->toBe(AssetHoldPreparationState::TIMED_OUT);
  $this->leases->applyFeedback(($this->feedback)('held'));
  expect($this->prepare->pollAt(51.0))->toBe(AssetHoldPreparationState::TIMED_OUT);
});

it('keeps ready resources owned through playback until explicit release', function () {
  $this->leases->applyFeedback(($this->feedback)('held'));
  expect($this->prepare->pollAt(10.5))->toBe(AssetHoldPreparationState::READY)
    ->and($this->leases->getDesiredRequests())->toBe($this->prepare->requests);
  $this->prepare->release();
  expect($this->leases->getDesiredRequests())->toBe([])
    ->and($this->prepare->pollAt(11.0))->toBe(AssetHoldPreparationState::CANCELLED);
});

it('reports refusal and unsupported sessions without waiting for the deadline', function (string $kind, AssetHoldPreparationState $expected) {
  if ($kind === 'refused') { $this->leases->applyFeedback(($this->feedback)('refused')); }
  else { $this->leases->startSession(false); }
  expect($this->prepare->pollAt(10.0))->toBe($expected);
})->with([
  ['refused', AssetHoldPreparationState::REFUSED],
  ['unsupported', AssetHoldPreparationState::UNSUPPORTED],
]);

it('detects a replaced owner set instead of reporting another request ready', function () {
  $this->prepare->scope->replaceResources(['return' => new AssetHoldResource('fixtures/replaced.png')]);
  expect($this->prepare->pollAt(10.1))->toBe(AssetHoldPreparationState::SUPERSEDED);
});

it('waits for a changed source attempt and survives a reconnect within the same deadline', function () {
  $this->leases->applyFeedback(($this->feedback)('held'));
  $this->leases->applyFeedback(($this->feedback)('ended'));
  $this->leases->applyFeedback(($this->feedback)('pending', 2));
  expect($this->prepare->pollAt(10.5))->toBe(AssetHoldPreparationState::WAITING);
  $this->leases->endSession();
  $this->leases->startSession(true);
  expect($this->prepare->pollAt(11.0))->toBe(AssetHoldPreparationState::WAITING);
  $this->leases->applyFeedback(($this->feedback)('held'));
  expect($this->prepare->pollAt(11.1))->toBe(AssetHoldPreparationState::READY);
});

it('releases weak ownership when an abandoned preparation is collected', function () {
  $other = new AssetHoldPreparation($this->leases, ['return' => $this->resource], 10, 2);
  $requests = $other->requests;
  expect($this->leases->getDesiredRequests())->toContain(...$requests);
  unset($other);
  foreach ($requests as $request) { expect($this->leases->getDesiredRequests())->not->toContain($request); }
});

it('validates deadlines and poll order before changing resource ownership', function (float $start, float $timeout) {
  $before = $this->leases->getDesiredRequests();
  expect(fn() => new AssetHoldPreparation($this->leases, ['other' => $this->resource], $start, $timeout))
    ->toThrow(InvalidArgumentException::class);
  expect($this->leases->getDesiredRequests())->toBe($before);
})->with([[NAN, 2], [10, INF], [10, 0], [10, -1], [PHP_FLOAT_MAX, PHP_FLOAT_MAX]]);

it('rejects backward and nonfinite polls without changing the pending decision', function () {
  $this->prepare->pollAt(11);
  foreach ([10.9, NAN, INF] as $now) {
    expect(fn() => $this->prepare->pollAt($now))->toThrow(InvalidArgumentException::class);
  }
  expect($this->prepare->state)->toBe(AssetHoldPreparationState::WAITING);
});


it('extends the live scope after the initial decision without starting a second readiness wait', function () {
  $this->leases->applyFeedback(($this->feedback)('held'));
  expect($this->prepare->pollAt(10.5))->toBe(AssetHoldPreparationState::READY);
  $initial = $this->prepare->requests;
  $this->prepare->scope->mergeResources(['outcome' => new AssetHoldResource('fixtures/outcome.png')]);
  expect($this->prepare->scope->getRequests())->toHaveCount(2)
    ->and($this->prepare->scope->getReadiness())->toBe(\Ichiloto\Engine\Rendering\Assets\AssetHoldReadiness::WAITING)
    ->and($this->prepare->requests)->toBe($initial)
    ->and($this->prepare->pollAt(20))->toBe(AssetHoldPreparationState::READY);
  $this->prepare->release();
  expect($this->leases->getDesiredRequests())->toBe([]);
});

it('merges known roles without creating another request and preserves unrelated owners on release', function () {
  $scope = new \Ichiloto\Engine\Rendering\Assets\AssetHoldScope($this->leases, ['new' => $this->resource]);
  $before = $scope->getRequests();
  $scope->mergeResources(['new' => $this->resource]);
  expect($scope->getRequests())->toBe($before);
  $scope->mergeResources(['outcome' => new AssetHoldResource('fixtures/outcome.png')]);
  expect($scope->getRequests())->toHaveCount(2)->and($scope->getRequests()[0])->toBe($before[0]);
  $scope->release(); $scope->release();
  expect($this->leases->getDesiredRequests())->toBe($this->prepare->requests)
    ->and(fn() => $scope->mergeResources(['again' => $this->resource]))->toThrow(LogicException::class);
});

it('refuses an over-capacity outcome atomically and retains the initial presentation resources', function () {
  $resources = [];
  for ($i = 0; $i < 1023; $i++) { $resources['role-' . $i] = $this->resource; }
  $scope = new \Ichiloto\Engine\Rendering\Assets\AssetHoldScope($this->leases, $resources);
  $before = $this->leases->getDesiredRequests();
  expect(fn() => $scope->mergeResources(['outcome' => new AssetHoldResource('fixtures/outcome.png')]))
    ->toThrow(InvalidArgumentException::class, '1024');
  expect($this->leases->getDesiredRequests())->toBe($before);
});

it('lets a frozen frame retain outcome resources until its own scope ends', function () {
  $this->prepare->scope->mergeResources(['outcome' => new AssetHoldResource('fixtures/outcome.png')]);
  $frozen = new stdClass();
  $requests = $this->prepare->scope->getRequests();
  $this->leases->borrowRequests($frozen, $requests);
  $this->prepare->release();
  expect($this->leases->getDesiredRequests())->toBe($requests);
  $this->leases->releaseOwner($frozen);
  expect($this->leases->getDesiredRequests())->toBe([]);
});


it('waits for real readiness without a deadline when the battle host selects feedback-only preparation', function () {
  $this->prepare = new AssetHoldPreparation($this->leases, ['battle' => $this->resource], 10, null);
  expect($this->prepare->deadline)->toBeNull()
    ->and($this->prepare->pollAt(10))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->prepare->pollAt(1000000))->toBe(AssetHoldPreparationState::WAITING);
  $this->leases->applyFeedback(($this->feedback)('held'));
  expect($this->prepare->pollAt(1000001))->toBe(AssetHoldPreparationState::READY);
});
