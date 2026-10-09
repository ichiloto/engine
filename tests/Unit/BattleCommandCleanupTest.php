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
use Symfony\Component\Console\Output\ConsoleOutput;
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

final class CommandCleanupScreen extends BattleScreen
{
  public ?string $announcement = 'Pending command';
  public int $controlRestores = 0;
  public int $selection = 0;
  public bool $failControls = false;

  public function __construct(private bool $graphical)
  {
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

function failCommandCleanupOutput(): void
{
  $output = new class extends ConsoleOutput {
    public function __construct() {}
    public function getStream() { throw new RuntimeException('Failed terminal sink'); }
  };
  new ReflectionProperty(Console::class, 'output')->setValue(null, $output);
}

it('keeps stage damage resolution separate from arena result feedback', function (bool $graphical) {
  [, $original] = createTargetExecutionFixture(false, $graphical, $this->createMock(AudioManager::class));
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
  [, $original] = createTargetExecutionFixture(false, $graphical, $this->createMock(AudioManager::class));
  $screen = new CommandCleanupScreen($graphical);
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
    [$engine, $original] = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    $screen = new CommandCleanupScreen($graphical);
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
  [, $original] = createTargetExecutionFixture(false, true, $this->createMock(AudioManager::class));
  $screen = new CommandCleanupScreen(true);
  $context = new TurnStateExecutionContext($original->game, $original->party, $original->troop, $screen, []);
  $old = createCommandCleanupRunner($context, static fn() => null);
  $next = createCommandCleanupRunner($context, static fn() => null);
  $old->begin();
  new ReflectionProperty(BattleCommandRunner::class, 'hidControls')->setValue($old, true);
  $next->begin();
  Console::replaceOverlay('battle-effect-flash', ['NEXT'], 2, 2, 1000);
  $old->dispose();
  expect($screen->fieldWindow->getCommandPlayback())->toBe($next->playback)
    ->and($screen->announcement)->toBe('Pending command')->and($screen->controlRestores)->toBe(0)
    ->and(Console::snapshot()->rows[2])->toContain('NEXT');
  $next->dispose();
});

it('retires a completed command after a failed final frame without replaying combat', function (bool $activeTime) {
  [$engine, $original] = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  $screen = new CommandCleanupScreen(false);
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
