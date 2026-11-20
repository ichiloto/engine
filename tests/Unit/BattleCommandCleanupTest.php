<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Presentation\BattleCommandRunner;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Symfony\Component\Console\Output\ConsoleOutput;
use function Tests\Support\Battle\createCommandFixtureScene;
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

final class CommandCleanupScreen extends BattleScreen
{
  public ?string $announcement = 'Pending command';
  public int $controlRestores = 0;
  public int $selection = 0;
  public bool $failControls = false;

  public function __construct(BattleScene $scene, private bool $graphical)
  {
    $this->battleScene = $scene;
    $this->fieldWindow = new class($this) extends BattleFieldWindow {
      public function __construct(BattleScreen $screen)
      {
        $this->battleScreen = $screen;
        $this->position = new Vector2();
        $this->width = 40;
        $this->height = 8;
      }
    };
    $this->characterNameWindow = new class($this) extends BattleCharacterNameWindow {
      public function __construct(private CommandCleanupScreen $screen) {}
      public function setActiveSelection(int $index, bool $blink = false): void { $this->screen->selection = $index; }
    };
    $this->characterStatusWindow = new class extends BattleCharacterStatusWindow {
      public function __construct() {}
      public function setCharacters(array $characters): void {}
    };
  }

  public function usesGraphicalField(): bool { return $this->graphical; }
  public function hideMessage(): void { $this->announcement = null; }
  public function showMessage(string $text): void { $this->announcement = $text; }
  public function refreshField(): void { Console::write('Resting formation', 0, 0); }
  public function showControls(): void
  {
    $this->controlRestores++;
    if ($this->failControls) { throw new RuntimeException('Control redraw failed'); }
  }
}

beforeEach(function () {
  $this->cleanupConsole = new ReflectionClass(Console::class)->getStaticProperties();
  $this->cleanupDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
  foreach (['overlays' => [], 'terminalHandedBack' => false, 'terminalOutputEnabled' => true,
    'usingAlternateScreen' => false, 'terminalOutputStream' => null, 'output' => null,
    'frameDepth' => 0, 'frameRows' => [], 'isRecomposing' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  ob_start();
  Console::syncDimensions(40, 8);
  Console::clear();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->cleanupConsole as $key => $value) { new ReflectionProperty(Console::class, $key)->setValue(null, $value); }
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->cleanupDelta);
});

function createCommandCleanupRunner(TurnStateExecutionContext $context, callable $resolve): BattleCommandRunner
{
  return new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, '',
    new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, null,
    $resolve, static fn() => [], static fn() => null);
}

it('holds command time and every cue while resources wait and starts with a fresh update boundary', function (string $outcome) {
  require_once __DIR__ . '/../Support/ManualRuntimeClock.php';
  $clock = new \Tests\Support\ManualRuntimeClock();
  $clockLease = \Tests\Support\DeclaredRuntimeClock::lease($clock);
  $runner = null;
  try {
    [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
    $leases = new \Ichiloto\Engine\Rendering\Assets\AssetHoldLeases();
    $leases->startSession(true);
    $preparation = null;
    $context->ui->prepareResources = static function ($playback, float $started, float $timeout) use ($leases, &$preparation) {
      return $preparation = new \Ichiloto\Engine\Rendering\Assets\AssetHoldPreparation($leases,
        ['return' => new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('fixtures/return.png')], $started, $timeout);
    };
    $resourceRefreshes = 0;
    $resolved = $cues = 0;
    $context->ui->refreshResources = static function ($scope, $playback) use (&$resourceRefreshes, &$resolved): void {
      expect($resolved)->toBe(1);
      $resourceRefreshes++;
      $scope->mergeResources(['actual-outcome' => new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('fixtures/outcome.png')]);
    };
    $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, '',
      new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, null,
      static function () use (&$resolved) { $resolved++; }, static fn() => [],
      static function () use (&$cues) { $cues++; });
    $runner->begin();
    $runner->update(30);
    expect($runner->playback->session->currentFrame)->toBe(0)->and($resolved)->toBe(0)->and($cues)->toBe(0)
      ->and($context->ui->fieldWindow->getCommandPlayback())->toBeNull();
    expect(fn() => $runner->update(INF))->toThrow(InvalidArgumentException::class);
    if ($outcome === 'cancel') {
      $runner->dispose();
      $runner->update(30);
      expect($resolved)->toBe(0)->and($cues)->toBe(0)->and($leases->getDesiredRequests())->toBe([]);
      return;
    }
    if ($outcome === 'timeout') { $clock->seconds += BattleCommandRunner::RESOURCE_PREPARATION_TIMEOUT_SECONDS; }
    else {
      $r = $preparation->requests[0];
      $leases->applyFeedback(\Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback::fromArray([
        'holds' => [['id' => $r->id, 'revision' => $r->revision, 'attempt' => 1,
          'state' => $outcome === 'ready' ? 'held' : 'refused', 'regionBytes' => 4096, 'baseBytes' => 0,
          ...($outcome === 'refused' ? ['reason' => 'over_budget'] : [])]],
        'holdCapacity' => createTestAssetHoldCapacity(4096, 1),
      ]));
    }
    $runner->update(30);
    expect($runner->playback->session->currentFrame)->toBe(0)->and($resolved)->toBe(0)
      ->and($context->ui->fieldWindow->getCommandPlayback())->toBe($runner->playback);
    if ($outcome === 'ready') { expect($runner->playback->presentationFailure)->toBeNull(); }
    else { expect($runner->playback->presentationFailure?->getMessage())->toContain($outcome === 'timeout' ? 'timed_out' : 'refused'); }
    $runner->update(30);
    $runner->update(30);
    expect($resolved)->toBe(1)->and($resourceRefreshes)->toBe(1)
      ->and(array_map(static fn($request) => $request->resource->asset, $leases->getDesiredRequests()))
      ->toContain('fixtures/return.png', 'fixtures/outcome.png')->and($cues)->toBeGreaterThan(0)->and($clock->sleeps)->toBe(0);
    $runner->dispose();
    expect($leases->getDesiredRequests())->toBe([]);
  } finally { $runner?->dispose(); $clockLease->close(); }
})->with(['ready', 'timeout', 'refused', 'cancel']);

function failCommandCleanupOutput(): void
{
  $output = new class extends ConsoleOutput {
    public function __construct() {}
    public function getStream() { throw new RuntimeException('Failed terminal sink'); }
  };
  new ReflectionProperty(Console::class, 'output')->setValue(null, $output);
}

it('waits without a deadline for required effect images and refuses without starting cues or combat', function (string $outcome) {
  require_once __DIR__ . '/../Support/ManualRuntimeClock.php';
  $clock = new \Tests\Support\ManualRuntimeClock();
  $clockLease = \Tests\Support\DeclaredRuntimeClock::lease($clock);
  $runner = null;
  try {
    [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
    $leases = new \Ichiloto\Engine\Rendering\Assets\AssetHoldLeases(); $leases->startSession(true);
    $preparation = null;
    $context->ui->prepareResources = static function ($playback, float $started) use ($leases, &$preparation) {
      return $preparation = new \Ichiloto\Engine\Rendering\Assets\AssetHoldPreparation($leases,
        ['effect' => new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('fixtures/effect.png')], $started, null);
    };
    $resolved = $cues = 0;
    $effect = new \Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline('effect', '', fps: 10,
      playbackSegments: [['layer' => 'image', 'startFrame' => 0, 'endFrame' => 1, 'drawCommands' => [[
        'trackId' => 'picture', 'assetId' => 'fixtures/effect.png', 'payload' => [],
      ]]]], defaults: ['lengthFrames' => 2]);
    $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, '',
      new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $effect,
      static function () use (&$resolved) { $resolved++; }, static fn() => [],
      static function () use (&$cues) { $cues++; });
    $runner->begin();
    $clock->seconds += 30;
    $runner->update(30);
    expect($preparation->deadline)->toBeNull()->and($runner->playback->session->currentFrame)->toBe(0)
      ->and($resolved)->toBe(0)->and($cues)->toBe(0)
      ->and($context->ui->fieldWindow->getCommandPlayback())->toBeNull();
    if ($outcome === 'cancelled') { $preparation->release(); }
    else {
      $r = $preparation->requests[0];
      $leases->applyFeedback(\Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback::fromArray([
        'holds' => [['id' => $r->id, 'revision' => $r->revision, 'attempt' => 1,
          'state' => $outcome === 'ready' ? 'held' : 'refused', 'regionBytes' => 4096, 'baseBytes' => 0,
          ...($outcome === 'refused' ? ['reason' => 'over_budget'] : [])]],
        'holdCapacity' => createTestAssetHoldCapacity(4096, 1),
      ]));
    }
    if ($outcome === 'ready') {
      $runner->update(30);
      expect($context->ui->fieldWindow->getCommandPlayback())->toBe($runner->playback)
        ->and($runner->playback->session->currentFrame)->toBe(0);
    } else {
      expect(fn() => $runner->update(30))->toThrow(
        \Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException::class, $outcome)
        ->and($context->ui->fieldWindow->getCommandPlayback())->toBeNull();
    }
    expect($resolved)->toBe(0);
    if ($outcome !== 'ready') { expect($cues)->toBe(0); }
  } finally { $runner?->dispose(); $clockLease->close(); }
})->with(['ready', 'refused', 'cancelled']);

it('keeps stage damage resolution separate from arena result feedback', function (bool $graphical) {
  [, $original] = createTargetExecutionFixture(false, $graphical, $this->createStub(AudioManager::class));
  $screen = $original->ui;
  $context = $original;
  $actor = $context->partyRoster->battlers[0];
  $target = $original->troop->members->toArray()[0];
  $target->stats->currentHp = 100;
  $stage = ['canvas' => ['width' => 400, 'height' => 300], 'startFrame' => 0,
    'restoreFrame' => 8,
    'camera' => [['id' => 'initial', 'frame' => 0, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1]]];
  $effect = new \Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene('stage', '', fps: 10,
    defaults: ['lengthFrames' => 10, 'stage' => $stage, 'effectTiming' => ['mode' => 'frame', 'frame' => 2]]);
  $resolutions = 0;
  $feedback = [];
  $runner = new BattleCommandRunner($context, $actor, [$target], null, 'Call',
    new BattleTurnTimings(0, 0, 0, 0, .2, .2, 0), null, $effect,
    function () use (&$resolutions, $target) { $resolutions++; $target->stats->currentHp -= 20; },
    static fn() => [['text' => '-20']],
    static function ($cue) use (&$feedback) { if ($cue['type'] === 'commandResult') { $feedback[] = $cue; } });
  $runner->begin();
  $impact = $runner->playback->plan->getCommandFrameForAuthoredFrame('target', 2);
  $runner->update($impact / \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline::FPS);
  expect($resolutions)->toBe(1)->and($target->stats->currentHp)->toBe(80)
    ->and($runner->playback->presentationFailure)->toBeNull()
    ->and($feedback)->toHaveCount($graphical ? 0 : 1)->and($screen->controlsHidden)->toBe($graphical ? 1 : 0);
  $reaction = $runner->playback->plan->phases['reaction']['start'];
  $runner->update(($reaction - $impact) / \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline::FPS);
  expect($feedback)->toHaveCount(1)->and($resolutions)->toBe(1)
    ->and($runner->playback->plan->getCinematicStageFrame($runner->playback->session->currentFrame))->toBeNull();
  if ($graphical) {
    expect($runner->playback->getPoseElapsedSeconds($target))->toBe(0.0);
  }
  $runner->dispose();
  expect($screen->controlsRestored)->toBe($graphical ? 1 : 0);
})->with([false, true]);

it('releases command state and its overlay even when terminal cleanup cannot be painted', function (bool $graphical) {
  [, $original] = createTargetExecutionFixture(false, $graphical, $this->createStub(AudioManager::class));
  $screen = new CommandCleanupScreen(createCommandFixtureScene($original->game), $graphical);
  $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
  $resolved = 0;
  $runner = createCommandCleanupRunner($context, function () use (&$resolved): void { $resolved++; });
  $runner->begin();
  $field = $screen->fieldWindow;
  $indicator = ['text' => '*', 'x' => 4, 'y' => 3];
  new ReflectionProperty(BattleFieldWindow::class, 'magicCastEffects')->setValue($field, [$indicator]);
  new ReflectionProperty(BattleFieldWindow::class, 'renderedTargetIndicators')->setValue($field, [$indicator]);
  $field->setPartyTargetQueue([0 => 1]);
  $field->focusPartyBattler(0);
  new ReflectionProperty(BattleCommandRunner::class, 'hidControls')->setValue($runner, true);
  Console::replaceOverlay('battle-effect-flash', ['FLASH'], 2, 2, 1000);
  Console::replaceOverlay('unrelated-notice', ['NOTICE'], 20, 2, 2000);
  failCommandCleanupOutput();

  $runner->dispose();
  expect($field->getCommandPlayback())->toBeNull()->and($field->getActingBattler())->toBeNull()
    ->and($screen->announcement)->toBeNull()->and($screen->controlRestores)->toBe(1)
    ->and($runner->playback->getPoseRole($runner->playback->actor))->toBe(BattlePoseRole::IDLE)
    ->and($runner->playback->getActiveSegments())->toBeEmpty()
    ->and(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('battle-effect-flash')
    ->toContain('unrelated-notice')->and(Console::isComposing())->toBeFalse();
  foreach (['magicCastEffects', 'renderedTargetIndicators', 'queuedPartyTargets', 'focusedPartyIndexes'] as $property) {
    expect(new ReflectionProperty(BattleFieldWindow::class, $property)->getValue($field))->toBeEmpty();
  }
  $runner->dispose();
  $runner->update(10);
  expect($resolved)->toBe(0)->and($screen->controlRestores)->toBe(1);
})->with([false, true]);

it('cleans up after frame rollback and preserves the original gameplay failure in both battle engines',
  function (bool $activeTime, bool $graphical, bool $retained) {
    [$engine, $original] = createTargetExecutionFixture($activeTime, $graphical, $this->createStub(AudioManager::class));
    $screen = new CommandCleanupScreen(createCommandFixtureScene($original->game), $graphical);
    $screen->failControls = true;
    $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
    $error = new RuntimeException('Original combat failure');
    $attempts = 0;
    $runner = createCommandCleanupRunner($context, function () use ($error, &$attempts): void { $attempts++; throw $error; });
    $runner->begin();
    new ReflectionProperty(BattleCommandRunner::class, 'hidControls')->setValue($runner, true);
    new ReflectionProperty(ActionExecutionState::class, 'command')->setValue($engine->actionExecutionState, $runner);
    Console::setLayerTracking($retained);
    Console::setTerminalOutputEnabled(!$retained);
    Console::setRetainedWorldPresentation($retained);
    Console::replaceOverlay('battle-effect-flash', ['FLASH'], 2, 2, 1000);
    Console::replaceOverlay('unrelated-notice', ['NOTICE'], 20, 2, 2000);
    failCommandCleanupOutput();
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $caught = null;
    try { $engine->actionExecutionState->update($context); }
    catch (Throwable $actual) { $caught = $actual; }
    expect($caught)->toBe($error)->and($attempts)->toBe(1)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and($screen->fieldWindow->getActingBattler())->toBeNull()
      ->and($screen->announcement)->toBeNull()->and($screen->controlRestores)->toBe(1)
      ->and($screen->selection)->toBe(-1)
      ->and(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('battle-effect-flash')
      ->toContain('unrelated-notice')->and(Console::isComposing())->toBeFalse();
    $runner->update(10);
    expect($attempts)->toBe(1);
  })->with([false, true])->with([false, true])->with([false, true]);

it('does not let a disposed old command erase a replacement commands presentation', function () {
  [, $original] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
  $screen = new CommandCleanupScreen(createCommandFixtureScene($original->game), true);
  $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
  $old = createCommandCleanupRunner($context, static fn() => null);
  $next = createCommandCleanupRunner($context, static fn() => null);
  $old->begin();
  new ReflectionProperty(BattleCommandRunner::class, 'hidControls')->setValue($old, true);
  $next->begin();
  $screen->showMessage('Next command');
  Console::replaceOverlay('battle-effect-flash', ['NEXT'], 2, 2, 1000);
  $old->dispose();
  expect($screen->fieldWindow->getCommandPlayback())->toBe($next->playback)
    ->and($screen->announcement)->toBe('Next command')->and($screen->controlRestores)->toBe(0)
    ->and(Console::snapshot()->rows[2])->toContain('NEXT');
  $next->dispose();
});

it('retires a completed command after a failed final frame without replaying combat', function (bool $activeTime) {
  [$engine, $original] = createTargetExecutionFixture($activeTime, false, $this->createStub(AudioManager::class));
  $screen = new CommandCleanupScreen(createCommandFixtureScene($original->game), false);
  $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
  $resolved = 0;
  $runner = createCommandCleanupRunner($context, function () use (&$resolved): void { $resolved++; });
  $runner->begin();
  new ReflectionProperty(ActionExecutionState::class, 'command')->setValue($engine->actionExecutionState, $runner);
  Console::replaceOverlay('battle-effect-flash', ['FLASH'], 2, 2, 1000);
  Console::replaceOverlay('unrelated-notice', ['NOTICE'], 20, 2, 2000);
  failCommandCleanupOutput();
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  expect(fn() => $engine->actionExecutionState->update($context))->toThrow(RuntimeException::class, 'Failed terminal sink');
  expect($resolved)->toBe(1)->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
    ->and($screen->fieldWindow->getActingBattler())->toBeNull()
    ->and(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('battle-effect-flash')
    ->toContain('unrelated-notice')->and(Console::isComposing())->toBeFalse();
  $runner->update(10);
  expect($resolved)->toBe(1);
})->with([false, true]);


it('ends ordinary callouts before source animation and overlaps summon callouts until their visibility expires', function (bool $graphical, bool $summon) {
  [, $original] = createTargetExecutionFixture(false, $graphical, $this->createStub(AudioManager::class));
  $screen = new CommandCleanupScreen(createCommandFixtureScene($original->game), $graphical);
  $screen->announcement = null;
  $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
  $target = $summon ? new \Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene('synthetic', '', fps: 10,
    defaults: ['lengthFrames' => 50, 'restFrame' => 0, 'name' => 'Synthetic summon']) : null;
  $resolved = 0;
  $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, 'Action name',
    new BattleTurnTimings(.1, .5, 2, 2, .1, .1, .1), null, $target,
    function () use (&$resolved): void { $resolved++; }, static fn() => [], static fn() => null);
  $runner->begin();
  $runner->update(.2);
  expect($screen->announcement)->toBe('Action name')->and($resolved)->toBe(0)
    ->and($runner->playback->phase)->toBe($summon ? 'source' : 'announce');
  $runner->update(.42);
  expect($runner->playback->phase)->toBe('source')->and($screen->announcement)->toBeNull()
    ->and($runner->playback->isCompleted)->toBeFalse()->and($resolved)->toBe(0);
  $runner->update(1);
  expect($screen->announcement)->toBeNull()->and($resolved)->toBe(0);
  $runner->update(100);
  expect($runner->playback->isCompleted)->toBeTrue()->and($resolved)->toBe(1);
  $runner->dispose();
})->with([false, true])->with([false, true]);


it('lets an authored summon message replace the callout without the callout timer clearing its replacement', function () {
  [, $original] = createTargetExecutionFixture(false, false, $this->createStub(AudioManager::class));
  $screen = new CommandCleanupScreen(createCommandFixtureScene($original->game), false);
  $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
  $target = new \Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene('visitor', '', fps: 10,
    cueSchedule: [['id' => 'caption', 'type' => 'showMessage', 'frame' => 1, 'payload' => ['text' => 'Authored caption']]],
    defaults: ['lengthFrames' => 50]);
  $runner = new BattleCommandRunner($context, $context->partyRoster->battlers[0], [], null, 'Action name',
    new BattleTurnTimings(.1, .5, .1, .1, .1, .1, .1), null, $target,
    static fn() => null, static fn() => [], static function ($cue) use ($screen): void {
      if ($cue['type'] === 'showMessage') { $screen->showMessage($cue['payload']['text']); }
    });
  $runner->begin();
  $runner->update(.35);
  expect($screen->announcement)->toBe('Authored caption');
  $runner->update(.5);
  expect($screen->announcement)->toBe('Authored caption');
  $runner->dispose();
  expect($screen->announcement)->toBeNull();
});


it('declares the actual post-result reaction outside combat resolution and never replays a failed resource handoff', function (bool $failHandoff) {
  [, $context] = createTargetExecutionFixture(false, true, $this->createStub(AudioManager::class));
  $leases = new \Ichiloto\Engine\Rendering\Assets\AssetHoldLeases(); $leases->startSession(true);
  $preparation = null;
  $context->ui->prepareResources = static function ($playback, $started, $timeout) use ($leases, &$preparation) {
    return $preparation = new \Ichiloto\Engine\Rendering\Assets\AssetHoldPreparation($leases, [], $started, $timeout);
  };
  $actor = $context->partyRoster->battlers[0]; $resolved = $refreshes = 0; $insideResolver = false;
  $context->ui->refreshResources = static function ($scope, $playback) use (&$refreshes, &$resolved, &$insideResolver, $actor, $failHandoff): void {
    expect($insideResolver)->toBeFalse()->and($resolved)->toBe(1)
      ->and($playback->getPoseRole($actor))->toBe(BattlePoseRole::DAMAGE);
    $refreshes++;
    if ($failHandoff) { throw new RuntimeException('Synthetic resource admission refused'); }
    $scope->mergeResources(['damage' => new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('fixtures/damage.png')]);
  };
  $runner = new BattleCommandRunner($context, $actor, [$actor], null, '',
    new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, null,
    static function () use (&$insideResolver, &$resolved, $actor): void {
      $insideResolver = true; $resolved++; $actor->stats->currentHp -= 1; $insideResolver = false;
    }, static fn() => [], static fn() => null);
  try {
    $runner->begin();
    $runner->update($runner->playback->plan->impactFrame / \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline::FPS);
    expect($resolved)->toBe(1)->and($refreshes)->toBe(1);
    if ($failHandoff) { expect($runner->playback->presentationFailure?->getMessage())->toBe('Synthetic resource admission refused'); }
    else { expect($preparation->scope->getRequests())->toHaveCount(1); }
    $runner->update(10); $runner->update(10);
    expect($resolved)->toBe(1)->and($refreshes)->toBe(1);
  } finally { $runner->dispose(); }
  expect($leases->getDesiredRequests())->toBe([]);
})->with([false, true]);
