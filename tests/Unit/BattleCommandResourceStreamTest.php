<?php

use Ichiloto\Engine\Animations\Timelines\{CompiledEffectTimeline, EffectPresentation};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandResourceStream, BattleCommandTimeline, BattlePoseRole};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Assets\{AssetHoldLeases, AssetHoldPrefetch, AssetHoldPreparationState, AssetHoldResource, AssetHoldScope};
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/AssetWindowFeedback.php';

function createCommandStreamFixture(?Closure $resolve = null): array
{
  $root = createTestDirectory('streamed-command');
  \Tests\Support\Rendering\writeTestPng($root . '/film.png', 32, 8);
  $timeline = new CompiledEffectTimeline('film', '', fps: 12,
    playbackSegments: array_map(static fn(int $frame): array => [
      'startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'image',
      'drawCommands' => [['trackId' => 'paint', 'assetId' => 'film.png', 'position' => ['x' => 0, 'y' => 0],
        'payload' => ['columns' => 4, 'rows' => 1, 'sourceFrame' => $frame]]]], range(0, 3)),
    cueSchedule: [['type' => 'applyEffect', 'frame' => 3]], defaults: ['lengthFrames' => 4, 'restFrame' => 2]);
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $resolved = new ArrayObject(['count' => 0, 'cues' => []]);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $timeline),
    $actor, [$actor], BattlePoseRole::ATTACK,
    function () use ($resolved, $resolve): void { $resolved['count']++; $resolve?->__invoke(); },
    function ($cue) use ($resolved): void { $resolved['cues'] = [...$resolved['cues'], $cue]; });
  $leases = new AssetHoldLeases(); $leases->startSession(true);
  $scene = new AssetHoldScope($leases, [new AssetHoldResource('synthetic/scene.png')]);
  replyAssetWindow($leases, $scene->getRequests());
  $mode = new ArrayObject(['reduced' => false]);
  $stream = new BattleCommandResourceStream($playback, $leases, $root,
    fn(): array => [$scene->getRequests()[0]->resource], fn(): array => $scene->getRequests(),
    fn(): bool => $mode['reduced'], new AssetHoldPrefetch($leases), 0);
  return [$stream, $playback, $leases, $scene, $resolved, $mode, $root];
}

it('walks every film cel with a bounded current/incoming cohort and holds clock and cues until each incoming cel is ready', function () {
  [$stream, $playback, $leases, $scene, $result] = createCommandStreamFixture();
  try {
    expect($stream->prepareInitialAt(0))->toBe(AssetHoldPreparationState::WAITING);
    expect($stream->initialPreparation->requests)->toHaveCount(2); // Scene + first cel, not the film.
    replyAssetWindow($leases, $stream->getPendingRequests());
    expect($stream->prepareInitialAt(1))->toBe(AssetHoldPreparationState::READY);
    $playback->begin(); $stream->afterAdvanceAt(1); $seen = []; $peak = 0;
    for ($tick = 2; $tick < 40 && !$playback->isCompleted; $tick++) {
      $advance = $stream->approveElapsedSeconds(30, $tick);
      $playback->update($advance);
      $stream->afterAdvanceAt($tick);
      $peak = max($peak, count($leases->getDesiredRequests()));
      foreach ($stream->getCurrentRequests() as $r) {
        if ($r->resource->asset === 'film.png') { $seen[$r->resource->sourceRect->x] = true; }
      }
      if (!$stream->canPresentAt($tick + .05)) {
        $before = [$playback->elapsedSeconds, $playback->session->currentFrame, $result['count'], $result['cues']];
        expect($stream->approveElapsedSeconds(1000, $tick + .1))->toBe(0.0)
          ->and($stream->canPresentAt($tick + .2))->toBeFalse();
        expect([$playback->elapsedSeconds, $playback->session->currentFrame, $result['count'], $result['cues']])->toBe($before);
      }
      if ($stream->getPendingRequests() !== []) { replyAssetWindow($leases, $stream->getPendingRequests()); }
    }
    ksort($seen);
    expect(array_keys($seen))->toBe([0, 8, 16, 24])->and($peak)->toBeLessThanOrEqual(3)
      ->and($playback->isCompleted)->toBeTrue()->and($result['count'])->toBe(1);
    $stream->release(); expect($leases->getDesiredRequests())->toBe($scene->getRequests());
  } finally { $stream->release(); $scene->release(); }
});

it('honours explicit refusal and motion-mode changes without advancing outcomes or silently using an unprepared picture', function () {
  [$stream, $playback, $leases, $scene, $result, $mode] = createCommandStreamFixture();
  try {
    replyAssetWindow($leases, $stream->getPendingRequests()); $stream->prepareInitialAt(0); $playback->begin();
    $before = [$playback->elapsedSeconds, $playback->session->currentFrame, $result['cues']];
    $playback->pause(); $mode['reduced'] = true;
    expect($stream->approveElapsedSeconds(30, 1))->toBe(0.0);
    $playback->resume();
    expect($stream->approveElapsedSeconds(30, 2))->toBe(0.0);
    $incoming = array_filter($stream->getPendingRequests(), fn($r) => $r->resource->asset === 'film.png');
    expect(array_values($incoming)[0]->resource->sourceRect->x)->toBe(16);
    replyAssetWindow($leases, array_values($incoming), 'refused');
    expect(fn() => $stream->canPresentAt(3))->toThrow(RendererTransportException::class, 'refused')
      ->and([$playback->elapsedSeconds, $playback->session->currentFrame, $result['cues']])->toBe($before)
      ->and($result['count'])->toBe(0);
  } finally { $stream->release(); $scene->release(); }
});

it('samples known result tails beyond the command timeline without firing cues or changing live state', function () {
  [$stream, $playback, $leases, $scene, $result, , $root] = createCommandStreamFixture();
  try {
    $tail = new CompiledEffectTimeline('result', '', fps: 12,
      playbackSegments: array_map(static fn(int $frame): array => [
        'startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'image',
        'drawCommands' => [['trackId' => 'result', 'assetId' => 'film.png',
          'payload' => ['columns' => 4, 'rows' => 1, 'sourceFrame' => $frame]]]], range(0, 3)),
      defaults: ['lengthFrames' => 4]);
    $last = $playback->session->totalFrames - 1;
    $playback->startResultEffect($playback->actor, $last, $tail, EffectPresentation::GRAPHICAL);
    $start = $playback->getElapsedSecondsAtFrame($last);
    $sample = $playback->createPresentationSampleAt($start + .2);
    expect($sample->session->isCompleted)->toBeTrue()
      ->and(array_column(array_merge(...array_column($sample->getActiveSegments(), 'drawCommands')), 'assetId'))->toContain('film.png')
      ->and($playback->session->currentFrame)->toBe(0)->and($result['count'])->toBe(0)->and($result['cues'])->toBe([]);
    $times = $playback->getResourceSampleTimes($start + .4);
    expect($times)->toContain($start + 4 / 12); // The tail's final boundary.
  } finally { $stream->release(); $scene->release(); }
});


it('loads the next drawing while a resident drawing plays and crosses a warmed boundary without a readiness stall', function () {
  [$stream, $playback, $leases, $scene] = createCommandStreamFixture();
  try {
    replyAssetWindow($leases, $stream->getPendingRequests()); $stream->prepareInitialAt(0); $playback->begin();
    $stream->afterAdvanceAt(1);
    $current = $stream->getCurrentRequests(); $next = $stream->getPendingRequests();
    expect(array_values(array_filter($next, fn($r) => $r->resource->asset === 'film.png'))[0]->resource->sourceRect->x)->toBe(8)
      ->and($stream->canPresentAt(1))->toBeTrue()->and($stream->getCurrentRequests())->toBe($current);
    replyAssetWindow($leases, $next);
    $at = $playback->plan->getCommandFrameForAuthoredFrame('target', 1) / BattleCommandTimeline::FPS;
    $approved = $stream->approveElapsedSeconds($at, 2);
    expect($approved)->toBe($at); // The warm cel is ready at its authored boundary.
    $playback->update($approved);
    expect($playback->session->currentFrame)->toBe($playback->plan->getCommandFrameForAuthoredFrame('target', 1))
      ->and($stream->getPendingRequests())->toBe([]);
  } finally { $stream->release(); $scene->release(); }
});

it('prepares no command resources for a battle whose Game runs no renderer', function () {
  $game = new class extends \Ichiloto\Engine\Core\Game { public function __construct() {} public function __destruct() {} };
  $scene = new class($game) extends \Ichiloto\Engine\Scenes\Battle\BattleScene {
    public function __construct(private \Ichiloto\Engine\Core\Game $fixtureGame) {}
    public function getGame(): \Ichiloto\Engine\Core\Game { return $this->fixtureGame; }
  };
  $screen = new class($scene) extends \Ichiloto\Engine\Battle\UI\BattleScreen {
    public function __construct(\Ichiloto\Engine\Scenes\Battle\BattleScene $scene) { $this->battleScene = $scene; }
    public function usesGraphicalField(): bool { return true; }
  };
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    new Character('Hero', 1, new Stats()), [], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);

  expect($game->getRendererRuntime())->toBeNull()
    ->and($screen->createCommandResourceStream($playback, 0.0))->toBeNull();
});
