<?php

use Ichiloto\Engine\Rendering\Assets\{AssetHoldFeedback, AssetHoldLeases, AssetHoldPreparation, AssetHoldPreparationState, AssetHoldResource, AssetHoldScope, AssetHoldWindow};

require_once __DIR__ . '/../Support/Rendering/AssetWindowFeedback.php';

beforeEach(function () {
  $this->leases = new AssetHoldLeases(); $this->leases->startSession(true);
  $this->a = new AssetHoldResource('synthetic/a.png');
  $this->b = new AssetHoldResource('synthetic/b.png');
  $this->scene = new AssetHoldScope($this->leases, [new AssetHoldResource('synthetic/scene.png')]);
  replyAssetWindow($this->leases, $this->scene->getRequests());
  $this->window = new AssetHoldWindow($this->leases);
});
afterEach(function () { $this->window->release(); $this->scene->release(); });

it('retains outgoing and scene resources until a small replacement cohort is ready, then releases only the outgoing cohort', function () {
  $shared = $this->scene->getRequests(); $sceneResource = $shared[0]->resource;
  $a = [$this->a, $sceneResource]; $b = [$this->b, $sceneResource];
  expect($this->window->selectResources($a, 0, $shared))->toBe(AssetHoldPreparationState::WAITING);
  $first = $this->window->getPendingRequests(); replyAssetWindow($this->leases, $first);
  expect($this->window->selectResources($a, .1, $shared))->toBe(AssetHoldPreparationState::READY);
  $current = $this->window->getCurrentRequests();
  expect($this->window->selectResources($b, .2, $shared))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->window->getCurrentRequests())->toBe($current)
    ->and($this->leases->getDesiredRequests())->toHaveCount(3);
  $next = $this->window->getPendingRequests();
  expect(array_find($next, fn($r) => $r->resource == $sceneResource))->toBe($shared[0]);
  replyAssetWindow($this->leases, $next);
  expect($this->window->selectResources($b, .3, $shared))->toBe(AssetHoldPreparationState::READY)
    ->and(array_map(fn($r) => $r->resource->asset, $this->leases->getDesiredRequests()))
    ->toEqualCanonicalizing(['synthetic/scene.png', 'synthetic/b.png']);
  $this->window->release(); expect($this->leases->getDesiredRequests())->toBe($shared);
});

it('rechecks changed-file feedback after a cohort was initially ready', function () {
  $this->window->selectResources([$this->a], 0);
  $requests = $this->window->getPendingRequests(); replyAssetWindow($this->leases, $requests);
  expect($this->window->selectResources([$this->a], .1))->toBe(AssetHoldPreparationState::READY);
  replyAssetWindow($this->leases, $requests, 'ended'); replyAssetWindow($this->leases, $requests, 'pending', 2);
  expect($this->window->selectResources([$this->a], 30))->toBe(AssetHoldPreparationState::WAITING);
  replyAssetWindow($this->leases, $requests, 'held', 2);
  expect($this->window->selectResources([$this->a], 31))->toBe(AssetHoldPreparationState::READY);
});

it('reports explicit failure without evicting the outgoing cohort or falling through on elapsed time', function () {
  $this->window->selectResources([$this->a], 0); replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->a], 1); $current = $this->window->getCurrentRequests();
  expect($this->window->selectResources([$this->b], 2))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->window->selectResources([$this->b], 1000))->toBe(AssetHoldPreparationState::WAITING);
  replyAssetWindow($this->leases, $this->window->getPendingRequests(), 'refused');
  $state = $this->window->selectResources([$this->b], 1001);
  expect($state)->toBe(AssetHoldPreparationState::REFUSED)
    ->and($this->window->describeSelectionFailure([$this->b], $state))
    ->toBe('refused: synthetic/b.png [over_budget] (base 0 bytes, region 4096 bytes)')
    ->and($this->window->getCurrentRequests())->toBe($current);
});

it('retires superseded lookahead instead of accumulating the future film', function () {
  $this->window->selectResources([$this->a], 0); replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->a], 1);
  for ($i = 0; $i < 100; $i++) {
    $this->window->selectResources([new AssetHoldResource('synthetic/next-' . $i . '.png')], $i + 2);
    expect($this->leases->getDesiredRequests())->toHaveCount(3);
  }
});

it('preserves frozen borrowers through cohort retirement and cancels both scopes idempotently', function () {
  $this->window->selectResources([$this->a], 0); replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->a], 1); $first = $this->window->getCurrentRequests();
  $frozen = new stdClass(); $this->leases->borrowRequests($frozen, $first);
  $this->window->selectResources([$this->b], 2); replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->b], 3); $this->window->release(); $this->window->release();
  expect($this->leases->getDesiredRequests())->toContain(...$first)->toHaveCount(2);
  $this->leases->releaseOwner($frozen); expect($this->leases->getDesiredRequests())->toBe($this->scene->getRequests());
  expect(fn() => $this->window->selectResources([], 4))->toThrow(LogicException::class);
});

it('validates cohort types, clock and initial session ownership without changing live cohorts', function () {
  $this->window->selectResources([$this->a], 10); $before = $this->leases->getDesiredRequests();
  foreach ([9, NAN, INF] as $now) { expect(fn() => $this->window->selectResources([$this->b], $now))->toThrow(InvalidArgumentException::class); }
  expect(fn() => $this->window->selectResources([new stdClass()], 11))->toThrow(InvalidArgumentException::class);
  expect($this->leases->getDesiredRequests())->toBe($before);
  $other = new AssetHoldLeases(); $other->startSession(true);
  $foreign = new AssetHoldPreparation($other, [$this->a], 0, null);
  expect(fn() => new AssetHoldWindow($this->leases, $foreign))->toThrow(InvalidArgumentException::class);
  $deadline = new AssetHoldPreparation($this->leases, [$this->b], 0, 2);
  expect(fn() => new AssetHoldWindow($this->leases, $deadline))->toThrow(InvalidArgumentException::class);
});

it('adopts the focused preparation without changing IDs and releases it on cancellation', function () {
  $initial = new AssetHoldPreparation($this->leases, [$this->a], 0, null);
  $window = new AssetHoldWindow($this->leases, $initial);
  expect($window->selectResources([$this->a], 1))->toBe(AssetHoldPreparationState::WAITING)
    ->and($window->getPendingRequests())->toBe($initial->requests);
  replyAssetWindow($this->leases, $initial->requests);
  expect($window->selectResources([$this->a], 2))->toBe(AssetHoldPreparationState::READY)
    ->and($window->getCurrentRequests())->toBe($initial->requests);
  $window->release(); expect($initial->state)->toBe(AssetHoldPreparationState::CANCELLED);
});


it('keeps a ready lookahead dormant and preserves it while the current picture is repainted', function () {
  $this->window->selectResources([$this->a], 0); replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->a], 1); $current = $this->window->getCurrentRequests();
  expect($this->window->prefetchResources([$this->b], 2))->toBe(AssetHoldPreparationState::WAITING);
  $incoming = $this->window->getPendingRequests(); replyAssetWindow($this->leases, $incoming);
  expect($this->window->prefetchResources([$this->b], 3))->toBe(AssetHoldPreparationState::READY)
    ->and($this->window->getCurrentRequests())->toBe($current);
  expect($this->window->selectResources([$this->a], 4))->toBe(AssetHoldPreparationState::READY)
    ->and($this->window->getPendingRequests())->toBe($incoming);
  expect($this->window->selectResources([$this->b], 5))->toBe(AssetHoldPreparationState::READY)
    ->and($this->window->getCurrentRequests())->toBe($incoming)->and($this->window->getPendingRequests())->toBe([]);
});

it('releases a partly refused lookahead while leaving the current picture held', function () {
  $this->window->selectResources([$this->a], 0);
  replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->a], 1);
  $current = $this->window->getCurrentRequests();
  $scene = $this->scene->getRequests();
  $incoming = [$this->b, new AssetHoldResource('synthetic/other.png')];
  expect($this->window->prefetchResources($incoming, 2))->toBe(AssetHoldPreparationState::WAITING);
  $prefetch = $this->window->getPendingRequests();
  replyAssetWindow($this->leases, [$prefetch[0]]);
  replyAssetWindow($this->leases, [$prefetch[1]], 'refused');

  expect($this->window->selectResources([$this->a], 3))->toBe(AssetHoldPreparationState::READY)
    ->and($this->window->getPendingRequests())->toBe([])
    ->and($this->leases->getDesiredRequests())->toBe([...$scene, ...$current]);
  expect($this->window->prefetchResources($incoming, 4))->toBe(AssetHoldPreparationState::REFUSED)
    ->and($this->leases->getDesiredRequests())->toBe([...$scene, ...$current]);

  expect($this->window->selectResources($incoming, 5))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->window->getCurrentRequests())->toBe($current);
  $required = $this->window->getPendingRequests();
  expect(array_intersect(array_column($prefetch, 'id'), array_column($required, 'id')))->toBe([]);
  replyAssetWindow($this->leases, $required);
  expect($this->window->selectResources($incoming, 6))->toBe(AssetHoldPreparationState::READY)
    ->and($this->window->getCurrentRequests())->toBe($required)
    ->and($this->leases->getDesiredRequests())->toBe([...$scene, ...$required]);
});

it('separates a declined lookahead from the required admission in the same poll', function () {
  $this->window->prefetchResources([$this->b], 1);
  $prefetch = $this->window->getPendingRequests();
  replyAssetWindow($this->leases, $prefetch, 'refused');

  expect($this->window->selectResources([$this->b], 2))->toBe(AssetHoldPreparationState::WAITING);
  $required = $this->window->getPendingRequests();
  expect($required[0]->id)->not->toBe($prefetch[0]->id);
  replyAssetWindow($this->leases, $required, 'refused');
  expect($this->window->selectResources([$this->b], 3))->toBe(AssetHoldPreparationState::REFUSED)
    ->and($this->window->getPendingRequests())->toBe($required);
});

it('keeps the outgoing picture when a once-ready lookahead starts a new file attempt', function () {
  $this->window->selectResources([$this->a], 0);
  replyAssetWindow($this->leases, $this->window->getPendingRequests());
  $this->window->selectResources([$this->a], 1);
  $current = $this->window->getCurrentRequests();
  $this->window->prefetchResources([$this->b], 2);
  $incoming = $this->window->getPendingRequests();
  replyAssetWindow($this->leases, $incoming);
  expect($this->window->prefetchResources([$this->b], 3))->toBe(AssetHoldPreparationState::READY);
  replyAssetWindow($this->leases, $incoming, 'ended');
  replyAssetWindow($this->leases, $incoming, 'pending', 2);
  expect($this->window->selectResources([$this->b], 4))->toBe(AssetHoldPreparationState::WAITING)
    ->and($this->window->getCurrentRequests())->toBe($current)
    ->and($this->window->getPendingRequests())->toBe($incoming);
  replyAssetWindow($this->leases, $incoming, 'held', 2);
  expect($this->window->selectResources([$this->b], 5))->toBe(AssetHoldPreparationState::READY)
    ->and($this->window->getCurrentRequests())->toBe($incoming);
});
