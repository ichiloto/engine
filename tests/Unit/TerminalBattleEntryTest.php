<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Battle\States\BattleStartState;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

final class TerminalEntryGameFixture extends Game
{
  public function __construct()
  {
    $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $this);
    new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
    $this->audioManager = new class extends AudioManager {
      public function __construct() {}
      public function stopBackgroundMusic(): void {}
    };
    $this->engine = new class($this) extends ActiveTimeBattleEngine {
      public int $starts = 0;
      public int $runs = 0;
      public function start(): void { $this->starts++; parent::start(); }
      public function run(BattleEngineContextInterface $context): void { $this->runs++; }
    };
  }
  public function __destruct() {}
}

final class TerminalEntrySceneFixture extends BattleScene
{
  /** @var list<string> */
  public array $authoredFrames = ["FIRST\nLONG FIRST ROW", 'SECOND', 'FINAL'];

  public function __construct(SceneManager $manager)
  {
    parent::__construct($manager, 'Terminal entry');
    $this->camera = new class extends Camera {
      public function __construct() {}
      public function start(): void {}
      public function stop(): void {}
      public function suspend(): void {}
      public function resizeViewport(int $width, int $height): void {}
    };
  }

  protected function initializeBattleSceneStates(): void
  {
    parent::initializeBattleSceneStates();
    $this->startState = new class($this->sceneStateContext) extends BattleStartState {
      protected function loadAnimationFrameData(): array { return $this->scene->authoredFrames; }
    };
  }
}

function advanceTerminalEntry(float $time): void
{
  new ReflectionProperty(Time::class, 'time')->setValue(null, $time);
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, Time::class, Timers::class, ConfigStore::class, EventManager::class, UIManager::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(Console::class, 'frameDepth')->setValue(null, 0);
  new ReflectionProperty(Console::class, 'isRecomposing')->setValue(null, false);
  new ReflectionProperty(Console::class, 'terminalHandedBack')->setValue(null, false);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, []);
  ConfigStore::put(ProjectConfig::class, new class extends ProjectConfig {
    protected function load(): array { return ['audio' => ['music' => false, 'sfx' => false]]; }
  });
  ConfigStore::put(PlaySettings::class, new PlaySettings(['screenDimensions' => ['width' => 135, 'height' => 36]]));
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  Console::clear();
  Console::write('OUTGOING FIELD', 0, 0);
  Timers::clear();
  $this->blockingTicks = 0;
  Timers::setFrameTick(function () { $this->blockingTicks++; });
  advanceTerminalEntry(0);
  $this->game = new TerminalEntryGameFixture();
  new ReflectionProperty(UIManager::class, 'instance')->setValue(null, new class($this->game) extends UIManager {
    public function __construct(Game $game) { parent::__construct($game); }
  });
  $this->scene = new TerminalEntrySceneFixture($this->game->sceneManager);
  $this->game->sceneManager->currentScene = $this->scene;
  $this->game->sceneManager->addScenes($this->scene);
  $this->scene->start();
  $party = new Party();
  $party->addMember(new Character('Entry hero', 1, new Stats(currentHp: 100)));
  $this->configuration = new BattleConfig($party, new Troop('Entry enemies'), settings: ['firstStrike' => 'normal']);
  $this->begin = function (): BattleStartState {
    $this->scene->configure($this->configuration);
    return $this->scene->state;
  };
});

afterEach(function () {
  try { $this->scene->stop(); } finally {
    foreach ($this->statics as $class => $state) {
      foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
  }
});

it('keeps authored intro frames and duration on the shared elapsed clock without running combat early', function () {
  $state = ($this->begin)();
  $session = new ReflectionProperty(BattleStartState::class, 'introPlayback')->getValue($state);
  expect($session)->toBeInstanceOf(EffectPlaybackSession::class)
    ->and(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'FIRST')
    ->and($this->game->engine->starts)->toBe(0)->and($session->secondsPerFrame * $session->totalFrames)->toBe(0.2);
  $before = Console::snapshot()->rows;
  $state->execute();
  expect(Console::snapshot()->rows)->toBe($before)->and($session->currentFrame)->toBe(0);
  advanceTerminalEntry(0.07); $state->execute();
  expect($session->currentFrame)->toBe(1)->and(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'SECOND')
    ->and(trim(Console::snapshot()->rows[1]))->toBe('');
  advanceTerminalEntry(0.14); $state->execute();
  expect(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'FINAL')->and($this->game->engine->starts)->toBe(0);
  advanceTerminalEntry(0.2); $state->execute();
  expect($session->isCompleted)->toBeTrue()->and($this->scene->state)->toBeInstanceOf(BattleRunState::class)
    ->and($this->game->engine->starts)->toBe(1)->and($this->blockingTicks)->toBe(0)
    ->and(implode('', Console::snapshot()->rows))->not->toContain('FINAL', 'FIRST', 'OUTGOING FIELD')
    ->and(array_filter(array_keys(new ReflectionProperty(Console::class, 'overlays')->getValue()),
      fn(string $id): bool => str_starts_with($id, 'battle-entry:')))->toBe([]);
});

it('does not advance paused intro playback or charge suspended time on resume', function () {
  $state = ($this->begin)();
  advanceTerminalEntry(0.07); $state->execute();
  $before = Console::snapshot()->rows;
  $this->scene->suspend();
  advanceTerminalEntry(100); $state->execute();
  expect(Console::snapshot()->rows)->toBe($before)->and($this->game->engine->starts)->toBe(0);
  advanceTerminalEntry(200); $this->scene->resume(); $state->execute();
  expect(Console::snapshot()->rows)->toBe($before)->and($this->game->engine->starts)->toBe(0);
  advanceTerminalEntry(200.07); $state->execute();
  expect(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'FINAL');
});

it('cleans only the owned intro overlay when stopped or exited and allows fresh entry', function () {
  $state = ($this->begin)();
  Console::replaceOverlay('unrelated', ['PRESERVED'], 5, 5, 4000);
  $this->scene->stop();
  expect(Console::snapshot()->rows[0])->toStartWith('OUTGOING FIELD')
    ->and(Console::snapshot()->rows[5])->toContain('PRESERVED')
    ->and($this->game->engine->starts)->toBe(0);
  $state->exit();
  $newState = ($this->begin)();
  expect($newState)->not->toBe($state)->and(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'FIRST');
});

it('redraws the current entry frame after resize without advancing or blocking', function () {
  $state = ($this->begin)();
  advanceTerminalEntry(0.07); $state->execute();
  Console::syncDimensions(150, 40);
  $this->scene->onScreenResize(150, 40);
  $session = new ReflectionProperty(BattleStartState::class, 'introPlayback')->getValue($state);
  expect($session->currentFrame)->toBe(1)->and($this->game->engine->starts)->toBe(0)
    ->and(implode('', Console::snapshot()->rows))->toContain('SECOND')
    ->and(Console::snapshot()->rows)->toHaveCount(40)->and($this->blockingTicks)->toBe(0);
});

it('finishes a long update once without replaying stale frames or starting combat twice', function () {
  $state = ($this->begin)();
  advanceTerminalEntry(20); $state->execute();
  $state->execute();
  expect($this->scene->state)->toBeInstanceOf(BattleRunState::class)
    ->and($this->game->engine->starts)->toBe(1)->and($this->blockingTicks)->toBe(0);
});

it('removes terminal entry motion under reduced motion while retaining the final authored frame', function () {
  ConfigStore::get(ProjectConfig::class)->set('accessibility.reducedMotion', true);
  $state = ($this->begin)();
  expect(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'FINAL')->and($this->game->engine->starts)->toBe(0);
  $state->execute();
  expect($this->scene->state)->toBeInstanceOf(BattleRunState::class)
    ->and($this->game->engine->starts)->toBe(1)->and($this->blockingTicks)->toBe(0);
});

it('emits only final terminal differences without clear sequences or an outgoing-field handoff flash', function (bool $tracking) {
  $stream = fopen('php://temp', 'w+');
  new ReflectionProperty(Console::class, 'terminalOutputStream')->setValue(null, $stream);
  new ReflectionProperty(Console::class, 'output')->setValue(null, null);
  Console::setLayerTracking($tracking);
  Console::setTerminalOutputEnabled(true);
  try {
    $state = ($this->begin)();
    $firstLength = ftell($stream);
    $state->execute();
    expect(ftell($stream))->toBe($firstLength);
    advanceTerminalEntry(0.14); $state->execute();
    rewind($stream);
    $frames = stream_get_contents($stream);
    expect($frames)->toContain('FIRST')->not->toContain("\033[2J", 'OUTGOING FIELD')
      ->and(Console::snapshot()->rows[0])->toStartWith(str_repeat(' ', $this->scene->ui->screenDimensions->getLeft()) . 'FINAL');
    ftruncate($stream, 0); rewind($stream);
    advanceTerminalEntry(0.2); $state->execute();
    rewind($stream);
    $handoff = stream_get_contents($stream);
    expect($handoff)->not->toBeEmpty()->not->toContain("\033[2J", 'OUTGOING FIELD', 'FINAL')
      ->and(Console::isComposing())->toBeFalse()->and($this->blockingTicks)->toBe(0);
  } finally {
    Console::setTerminalOutputEnabled(false);
    new ReflectionProperty(Console::class, 'terminalOutputStream')->setValue(null, null);
    if (is_resource($stream)) { fclose($stream); }
  }
})->with(['terminal' => [false], 'retained terminal' => [true]]);

it('preserves the authored entry duration when its frame count exceeds the timeline rate ceiling', function () {
  $this->scene->authoredFrames = array_map(static fn(int $index): string => 'FRAME ' . $index, range(0, 30));
  $state = ($this->begin)();
  $session = new ReflectionProperty(BattleStartState::class, 'introPlayback')->getValue($state);
  expect($session->fps)->toBe(120)->and($session->totalFrames)->toBe(31)
    ->and($session->secondsPerFrame * $session->totalFrames)->toEqualWithDelta(0.2, 1e-12);
  advanceTerminalEntry(0.199); $state->execute();
  expect($this->scene->state)->toBe($state)->and($this->game->engine->starts)->toBe(0);
  advanceTerminalEntry(0.2); $state->execute();
  expect($this->scene->state)->toBeInstanceOf(BattleRunState::class)->and($this->game->engine->starts)->toBe(1);
});
