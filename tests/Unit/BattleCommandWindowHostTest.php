<?php

use Ichiloto\Engine\Animations\Timelines\{CompiledEffectTimeline, EffectPresentation};
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\{AttackAction, ExecutionEligibility};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandResourceStream, BattleCommandRunner};
use Ichiloto\Engine\Core\Clocks\RuntimeClock;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Assets\{AssetHoldLeases, AssetHoldPrefetch};
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Tests\Support\ManualRuntimeClock;
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/AssetWindowFeedback.php';
require_once __DIR__ . '/../Support/ManualRuntimeClock.php';

beforeEach(function () {
  $this->windowHostConsole = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['overlays' => [], 'terminalHandedBack' => false, 'terminalOutputEnabled' => true,
    'usingAlternateScreen' => false, 'terminalOutputStream' => null, 'output' => null,
    'frameDepth' => 0, 'frameRows' => [], 'isRecomposing' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  ob_start(); Console::syncDimensions(40, 8); Console::clear();
});
afterEach(function () {
  ob_end_clean();
  foreach ($this->windowHostConsole as $key => $value) { new ReflectionProperty(Console::class, $key)->setValue(null, $value); }
});

function createWindowHostEffect(string $root, string $asset, int $impact): CompiledEffectTimeline
{
  \Tests\Support\Rendering\writeTestPng($root . '/' . $asset, 32, 8);
  return new CompiledEffectTimeline($asset, '', fps: 12,
    playbackSegments: array_map(static fn(int $frame): array => ['startFrame' => $frame, 'endFrame' => $frame,
      'layer' => 'image', 'drawCommands' => [['trackId' => 'paint', 'assetId' => $asset,
        'position' => ['x' => 0, 'y' => 0], 'payload' => ['columns' => 4, 'rows' => 1, 'sourceFrame' => $frame]]]], range(0, 3)),
    cueSchedule: [['type' => 'applyEffect', 'frame' => $impact]], defaults: ['lengthFrames' => 4]);
}

it('gates actual runner outcomes and freshly known result pictures, resumes without catch-up, and releases the host stream', function () {
  $clock = new ManualRuntimeClock(); $clockLease = RuntimeClock::useClock($clock); $runner = null;
  try {
    [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
    $root = createTestDirectory('runner-window-host');
    $effect = createWindowHostEffect($root, 'action.png', 3);
    $tail = createWindowHostEffect($root, 'outcome.png', 0);
    $leases = new AssetHoldLeases(); $leases->startSession(true); $stream = null;
    $context->ui->createResources = static function ($playback, float $start) use ($root, $leases, &$stream) {
      return $stream = new BattleCommandResourceStream($playback, $leases, $root,
        static fn(): array => [], static fn(): array => [], static fn(): bool => false, new AssetHoldPrefetch($leases), $start);
    };
    $resolved = 0;
    $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [$context->partyRoster->battlers[0]], null, '',
      new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $effect,
      function () use (&$runner, &$resolved, $tail): void {
        $resolved++;
        $runner->playback->startResultEffect($runner->playback->actor, $runner->playback->plan->impactFrame, $tail, EffectPresentation::GRAPHICAL);
      }, static fn(): array => [], static function (): void {});
    $runner->begin();
    expect($context->ui->canPresentCommandFrame())->toBeFalse()->and($resolved)->toBe(0);
    $seen = []; $sawOutcomeWait = false;
    for ($tick = 0; $tick < 120 && !$runner->playback->isCompleted; $tick++) {
      $clock->seconds += 1;
      $before = [$runner->playback->elapsedSeconds, $runner->playback->session->currentFrame, $resolved];
      $ready = $context->ui->canPresentCommandFrame();
      $runner->update($ready ? 1 / 60 : 1000);
      if (!$ready) {
        expect([$runner->playback->elapsedSeconds, $runner->playback->session->currentFrame, $resolved])->toBe($before);
      }
      $afterReady = $context->ui->canPresentCommandFrame();
      if (!$afterReady && $resolved === 1) { $sawOutcomeWait = true; }
      foreach ($stream->getCurrentRequests() as $r) { $seen[$r->resource->asset][$r->resource->sourceRect->x] = true; }
      if ($stream->getPendingRequests() !== []) { replyAssetWindow($leases, $stream->getPendingRequests()); }
    }
    foreach (['action.png', 'outcome.png'] as $asset) { ksort($seen[$asset]); expect(array_keys($seen[$asset]))->toBe([0, 8, 16, 24]); }
    $this->assertTrue($sawOutcomeWait, 'Newly resolved outcome images must enter their readiness barrier.');
    $this->assertSame(1, $resolved);
    $this->assertTrue($runner->playback->isCompleted, json_encode([
      'elapsed' => $runner->playback->elapsedSeconds, 'frame' => $runner->playback->session->currentFrame,
      'duration' => $runner->playback->getDurationSeconds(), 'sessionCompleted' => $runner->playback->session->isCompleted,
      'current' => array_map(fn($r) => [$r->resource->asset, $r->resource->sourceRect?->x], $stream->getCurrentRequests()),
      'pending' => array_map(fn($r) => [$r->resource->asset, $r->resource->sourceRect?->x], $stream->getPendingRequests())]));
    expect($clock->sleeps)->toBe(0);
    $runner->dispose(); expect($context->ui->canPresentCommandFrame())->toBeTrue()->and($leases->getDesiredRequests())->toBe([]);
  } finally { $runner?->dispose(); $clockLease->close(); }
});

it('releases pending artwork if command eligibility expires before playback', function () {
  $clock = new ManualRuntimeClock();
  $clockLease = RuntimeClock::useClock($clock);
  $runner = null;
  try {
    [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
    $root = createTestDirectory('runner-window-eligibility');
    $effect = createWindowHostEffect($root, 'action.png', 3);
    $leases = new AssetHoldLeases();
    $leases->startSession(true);
    $context->ui->createResources = static fn($playback, float $start) => new BattleCommandResourceStream(
      $playback, $leases, $root, static fn(): array => [], static fn(): array => [],
      static fn(): bool => false, new AssetHoldPrefetch($leases), $start
    );
    $action = new class('Test command') extends AttackAction implements ExecutionEligibility {
      public bool $available = true;

      public function getExecutionRefusal(CharacterInterface $actor): ?string
      {
        return $this->available ? null : 'Test command is unavailable.';
      }
    };
    $resolved = 0;
    $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], $action, '',
      new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $effect,
      function () use (&$resolved): void { $resolved++; }, static fn(): array => [], static function (): void {});
    $runner->begin();
    $originalPlayback = $runner->playback;
    $pending = $leases->getDesiredRequests();
    expect($pending)->not->toBeEmpty()
      ->and($context->ui->canPresentCommandFrame())->toBeFalse();

    $action->available = false;
    $runner->update(1 / 60);

    expect($leases->getDesiredRequests())->toBe([])
      ->and($runner->playback)->not->toBe($originalPlayback)
      ->and($originalPlayback->isCancelled)->toBeTrue()
      ->and($context->ui->canPresentCommandFrame())->toBeTrue()
      ->and($context->ui->announcement)->toBe('Test command is unavailable.');

    replyAssetWindow($leases, $pending);
    $runner->update(1);
    expect($leases->getDesiredRequests())->toBe([])->and($resolved)->toBe(0);
  } finally {
    $runner?->dispose();
    $clockLease->close();
  }
});

it('keeps a refused runner window fatal until cancellation instead of painting or advancing through it', function () {
  $clock = new ManualRuntimeClock(); $clockLease = RuntimeClock::useClock($clock); $runner = null;
  try {
    [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
    $root = createTestDirectory('runner-window-refusal'); $effect = createWindowHostEffect($root, 'action.png', 3);
    $leases = new AssetHoldLeases(); $leases->startSession(true); $stream = null;
    $context->ui->createResources = static function ($playback, float $start) use ($root, $leases, &$stream) {
      return $stream = new BattleCommandResourceStream($playback, $leases, $root,
        static fn(): array => [], static fn(): array => [], static fn(): bool => false, new AssetHoldPrefetch($leases), $start);
    };
    $resolved = 0;
    $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, '',
      new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $effect,
      function () use (&$resolved): void { $resolved++; }, static fn(): array => [], static function (): void {});
    $runner->begin(); replyAssetWindow($leases, $stream->getPendingRequests(), 'refused');
    $diagnostic = 'refused: action.png (0,0 8x8) [over_budget] (base 0 bytes, region 4096 bytes)';
    expect(fn() => $context->ui->canPresentCommandFrame())->toThrow(RendererTransportException::class, $diagnostic)
      ->and(fn() => $runner->update(1000))->toThrow(RendererTransportException::class, $diagnostic)
      ->and($resolved)->toBe(0)->and($runner->playback->session->currentFrame)->toBe(0);
    $runner->dispose(); expect($leases->getDesiredRequests())->toBe([])->and($context->ui->canPresentCommandFrame())->toBeTrue();
  } finally { $runner?->dispose(); $clockLease->close(); }
});


it('preserves the existing warm command clock across resource decisions and large updates', function (float $delta) {
  $clock = new ManualRuntimeClock(); $clockLease = RuntimeClock::useClock($clock); $runner = null; $scene = null;
  try {
    [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
    $root = createTestDirectory('warm-runner-clock'); $effect = createWindowHostEffect($root, 'action.png', 3);
    $leases = new AssetHoldLeases(); $leases->startSession(true);
    $scene = new \Ichiloto\Engine\Rendering\Assets\AssetHoldScope($leases,
      \Ichiloto\Engine\Battle\Presentation\BattleEffectResources::collectTimelineResources($effect, $root));
    replyAssetWindow($leases, $scene->getRequests());
    $context->ui->createResources = static fn($playback, float $start) => new BattleCommandResourceStream($playback, $leases, $root,
      static fn(): array => [], fn(): array => $scene->getRequests(), static fn(): bool => false, new AssetHoldPrefetch($leases), $start);
    $actualResolved = $expectedResolved = 0;
    $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, '',
      new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $effect,
      function () use (&$actualResolved): void { $actualResolved++; }, static fn(): array => [], static function (): void {});
    $reference = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback($runner->playback->plan, $runner->playback->actor, [], $runner->playback->pose,
      function () use (&$expectedResolved): void { $expectedResolved++; }, static function (): void {});
    $runner->begin(); $reference->begin();
    for ($tick = 0; $tick < 100 && !$reference->isCompleted; $tick++) {
      $clock->seconds += $delta;
      $runner->update($delta); $reference->update($delta);
      expect($runner->playback->session->currentFrame)->toBe($reference->session->currentFrame)
        ->and($actualResolved)->toBe($expectedResolved)
        ->and($runner->playback->isCompleted)->toBe($reference->isCompleted);
      $this->assertEqualsWithDelta($reference->elapsedSeconds, $runner->playback->elapsedSeconds, 1e-12);
    }
  } finally { $runner?->dispose(); $scene?->release(); $clockLease->close(); }
})->with([1 / 60, .37, 30.0]);
