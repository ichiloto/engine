<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback;
use Ichiloto\Engine\Rendering\Assets\AssetHoldLeases;
use Ichiloto\Engine\Rendering\Assets\AssetHoldReadiness;
use Ichiloto\Engine\Rendering\Assets\AssetHoldRequest;
use Ichiloto\Engine\Rendering\Assets\AssetHoldState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

beforeEach(function () {
  $this->leases = new AssetHoldLeases();
  $this->a = new stdClass();
  $this->b = new stdClass();
  $this->image = new CanvasImage('drawing', 'fixtures/replaceable.png', new CanvasRectangle(0, 0, 32, 48));
  $this->reply = static function (array $statuses): AssetHoldFeedback {
    return AssetHoldFeedback::fromArray(['holds' => $statuses, 'holdCapacity' => createTestAssetHoldCapacity(4096, 1)]);
  };
  $this->status = static function (AssetHoldRequest $request, string $state, int $attempt = 1): array {
    return ['id' => $request->id, 'revision' => $request->revision, 'attempt' => $attempt, 'state' => $state,
      ...($state === 'ended' ? ['reason' => 'changed'] : ['regionBytes' => 4096, 'baseBytes' => 0]),
      ...($state === 'refused' ? ['reason' => 'over_budget'] : [])];
  };
});

it('reads the shared ACK and bounded newest-attempt event with one shared image capacity', function () {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-holds-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  foreach ([$fixture['frameAck']['changedGeneration'], $fixture['event']['example']] as $wire) {
    $feedback = AssetHoldFeedback::fromArray(['holds' => $wire['holds'], 'holdCapacity' => $wire['holdCapacity']]);
    expect(count($feedback->statuses))->toBe(count($wire['holds']))
      ->and($feedback->capacity->bytes)->toBe($wire['holdCapacity']['bytes'])
      ->and($feedback->capacity->held->bytes)->toBe($wire['holdCapacity']['owners']['held']['bytes'])
      ->and($feedback->capacity->staging->limitBytes)->toBe($wire['holdCapacity']['owners']['staging']['limitBytes']);
  }
  $object = json_decode(json_encode($fixture['event']['example'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
  expect(AssetHoldFeedback::fromArray(['holds' => $object->holds, 'holdCapacity' => $object->holdCapacity])->statuses[1]->state)
    ->toBe(AssetHoldState::PENDING);
});

it('keeps independent owner references even when the renderer will share their pixel key', function () {
  $a = $this->leases->replaceImages($this->a, ['return' => $this->image])[0];
  $b = $this->leases->replaceImages($this->b, ['return' => $this->image])[0];
  expect($a->id)->not->toBe($b->id)->and($b->revision)->toBeGreaterThan($a->revision);
  $this->leases->startSession(true);
  $this->leases->applyFeedback(($this->reply)([($this->status)($a, 'held'), ($this->status)($b, 'pending')]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::READY)
    ->and($this->leases->getReadiness($this->b))->toBe(AssetHoldReadiness::WAITING);
  $this->leases->releaseOwner($this->a);
  expect($this->leases->getDesiredRequests())->toBe([$b]);
  $this->leases->applyFeedback(($this->reply)([($this->status)($a, 'held'), ($this->status)($b, 'held')]));
  expect($this->leases->getStatuses($this->a))->toBe([])
    ->and($this->leases->getReadiness($this->b))->toBe(AssetHoldReadiness::READY);
});

it('preserves a lease through layout changes and issues a fresh revision only for a different preparation', function () {
  $before = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $moved = new CanvasImage('another-drawing-id', $this->image->asset, new CanvasRectangle(20, 30, 64, 96), layer: 2);
  expect($this->leases->replaceImages($this->a, ['role' => $moved])[0])->toBe($before);
  $turned = new CanvasImage('another-drawing-id', $this->image->asset, $moved->destination, flipX: true);
  $after = $this->leases->replaceImages($this->a, ['role' => $turned])[0];
  expect($after->id)->not->toBe($before->id)->and($after->revision)->toBeGreaterThan($before->revision)
    ->and($after->resource->base->flipX)->toBeTrue();
});

it('makes explicit reread an edge that survives identical replays without firing again', function () {
  $before = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $refresh = $this->leases->rereadResource($this->a, 'role');
  expect($refresh->revision)->toBeGreaterThan($before->revision)->and($refresh->reread)->toBeTrue()
    ->and($this->leases->replaceImages($this->a, ['role' => $this->image])[0])->toBe($refresh);
  $this->leases->startSession(true);
  $this->leases->applyFeedback(($this->reply)([($this->status)($before, 'held')]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING);
});

it('keeps newer source attempts ready when old completions or pending ACK snapshots arrive late', function () {
  $request = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $this->leases->startSession(true);
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held')]));
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'ended'), ($this->status)($request, 'pending', 2)]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING);
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held')]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING);
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held', 2)]));
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'pending', 2)]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::READY)
    ->and($this->leases->getStatuses($this->a)[0]->attempt)->toBe(2);
});

it('requires fresh residency after reconnect and reports unsupported hosts explicitly', function () {
  $request = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $this->leases->startSession(true);
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held')]));
  $this->leases->endSession();
  expect($this->leases->getDesiredRequests())->toBe([$request])
    ->and($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING);
  $this->leases->startSession(false);
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::UNSUPPORTED);
  $this->leases->startSession(true);
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING);
});

it('leaves owner sets unchanged when a replacement exceeds the session limit', function () {
  $a = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $b = $this->leases->replaceImages($this->b, ['role' => $this->image])[0];
  expect(fn() => $this->leases->replaceImages($this->a, array_fill(0, 1024, $this->image)))
    ->toThrow(InvalidArgumentException::class, 'asset_hold lease_limit');
  expect($this->leases->getDesiredRequests())->toBe([$a, $b]);
  $this->leases->releaseOwner($this->b);
  expect(count($this->leases->replaceImages($this->a, array_fill(0, 1024, $this->image))))->toBe(1024);
});

it('cleans orphaned owners and ignores late completions after explicit cancellation', function () {
  $owner = new stdClass();
  $request = $this->leases->replaceImages($owner, ['role' => $this->image])[0];
  $this->leases->startSession(true);
  unset($owner);
  expect($this->leases->getDesiredRequests())->toBe([]);
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held')]));
  expect($this->leases->getDesiredRequests())->toBe([]);
  $live = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $this->leases->replaceImages($this->a, []);
  $this->leases->applyFeedback(($this->reply)([($this->status)($live, 'held')]));
  expect($this->leases->getStatuses($this->a))->toBe([]);
});

it('reports refusal without calling it ready and refuses contradictory feedback atomically', function () {
  $a = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $b = $this->leases->replaceImages($this->b, ['role' => $this->image])[0];
  $this->leases->startSession(true);
  $this->leases->applyFeedback(($this->reply)([($this->status)($a, 'pending'), ($this->status)($b, 'held')]));
  expect(fn() => $this->leases->applyFeedback(($this->reply)([($this->status)($a, 'held'), ($this->status)($b, 'refused')])))
    ->toThrow(LogicException::class, 'Contradictory asset hold transition');
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING)
    ->and($this->leases->getReadiness($this->b))->toBe(AssetHoldReadiness::READY);
  $this->leases->applyFeedback(($this->reply)([($this->status)($a, 'refused')]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::REFUSED);
});

it('rejects malformed status fields and impossible reported capacity before changing any lease', function () {
  $request = $this->leases->replaceImages($this->a, ['role' => $this->image])[0];
  $this->leases->startSession(true);
  $valid = ($this->status)($request, 'held');
  foreach ([['attempt' => 0], ['revision' => true], ['regionBytes' => -1], ['baseBytes' => '0'],
    ['state' => 'ready'], ['reason' => 'missing'], ['message' => 'unexpected']] as $change) {
    expect(fn() => ($this->reply)([array_replace($valid, $change)]))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => AssetHoldFeedback::fromArray(['holds' => [], 'holdCapacity' => array_replace(createTestAssetHoldCapacity(2, 1), ['bytes' => 1])]))->toThrow(InvalidArgumentException::class);
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::WAITING);
});

it('lets a frozen handoff share wire leases and retain old resources while its producer changes', function () {
  $old = $this->leases->replaceImages($this->a, ['return' => $this->image])[0];
  $handoff = new stdClass();
  $this->leases->borrowRequests($handoff, [$old]);
  expect($this->leases->getDesiredRequests())->toBe([$old]);
  $newImage = new CanvasImage('replacement', 'fixtures/replacement.png', $this->image->destination);
  $new = $this->leases->replaceImages($this->a, ['return' => $newImage])[0];
  expect($new->id)->not->toBe($old->id)
    ->and($this->leases->getDesiredRequests())->toContain($old, $new);
  $this->leases->releaseOwner($this->a);
  expect($this->leases->getDesiredRequests())->toBe([$old]);
  $this->leases->releaseOwner($handoff);
  expect($this->leases->getDesiredRequests())->toBe([])
    ->and(fn() => $this->leases->borrowRequests(new stdClass(), [$old]))
    ->toThrow(LogicException::class, 'currently owned request');
});

it('counts borrowed ownership once at the lease ceiling and refuses a replacement that still needs the old set', function () {
  $requests = $this->leases->replaceImages($this->a, array_fill(0, 1024, $this->image));
  $this->leases->borrowRequests($this->b, $requests);
  expect(count($this->leases->getDesiredRequests()))->toBe(1024);
  $different = new CanvasImage('replacement', 'fixtures/other.png', $this->image->destination);
  expect(fn() => $this->leases->replaceImages($this->a, ['new' => $different]))
    ->toThrow(InvalidArgumentException::class, 'asset_hold lease_limit');
  expect($this->leases->getDesiredRequests())->toBe($requests);
});


it('accepts coalesced source attempts without requiring their skipped ended or pending history', function () {
  $request = $this->leases->replaceImages($this->a, ['return' => $this->image])[0];
  $this->leases->startSession(true);
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held')]));
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'held', 300)]));
  $this->leases->applyFeedback(($this->reply)([($this->status)($request, 'pending', 2)]));
  expect($this->leases->getReadiness($this->a))->toBe(AssetHoldReadiness::READY)
    ->and($this->leases->getStatuses($this->a)[0]->attempt)->toBe(300);
});

it('bounds feedback entry count even when all entries repeat one id', function () {
  $request = $this->leases->replaceImages($this->a, ['return' => $this->image])[0];
  expect(($this->reply)(array_fill(0, 1024, ($this->status)($request, 'pending')))->statuses)->toHaveCount(1024);
  expect(fn() => ($this->reply)(array_fill(0, 1025, ($this->status)($request, 'pending'))))
    ->toThrow(InvalidArgumentException::class, '1024');
});

it('validates refusal message bytes without truncating backend diagnostics or admitting broken UTF-8', function () {
  $request = $this->leases->replaceImages($this->a, ['return' => $this->image])[0];
  $status = ($this->status)($request, 'refused');
  $text = str_repeat('界', 341) . '!';
  expect(strlen($text))->toBe(1024);
  $accepted = ($this->reply)([[...$status, 'message' => $text]]);
  expect($accepted->statuses[0]->message)->toBe($text);
  foreach ([$text . '!', "\xFF"] as $bad) {
    expect(fn() => ($this->reply)([[...$status, 'message' => $bad]]))->toThrow(InvalidArgumentException::class, 'UTF-8');
  }
});
