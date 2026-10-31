<?php

declare(strict_types=1);

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Events\BattleEventPage;
use Ichiloto\Engine\Battle\Events\BattleEventRunner;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleEventState;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Battle\States\BattleVictoryState;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

final class EventSceneGame extends Game
{
  public function __construct()
  {
    $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $this);
    new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
    $this->engine = new class($this) extends ActiveTimeBattleEngine {
      public int $runs = 0;
      public function run(BattleEngineContextInterface $context): void { $this->runs++; }
    };
  }
  public function __destruct() {}
}

final class EventSceneWorld extends GameScene
{
  public function __construct()
  {
    $this->gameState = new GameState();
  }
  public function onEventSessionStarted(EventExecutionSession $session): void {}
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
}

final class EventSceneLines implements EventPresentationInterface
{
  public array $lines = [];
  public bool $showing = false;
  public function beginText(string $text, string $speaker): void { $this->lines[] = $text; $this->showing = true; }
  public function beginChoice(string $prompt, array $options, string $title = ''): void {}
  public function update(): void {}
  public function render(): void {}
  public function isComplete(): bool { return ! $this->showing; }
  public function choiceResult(): ?int { return null; }
  public function reset(): void { $this->showing = false; }
}

final class EventBattleFixture extends BattleScene
{
  public function __construct(EventSceneGame $game, Troop $troop, Party $party, EventSceneLines $lines)
  {
    $this->sceneManager = $game->sceneManager;
    $this->eventManager = EventManager::getInstance($game);
    $this->camera = new class extends Camera {
      public function __construct() {}
      public function start(): void {}
      public function update(): void {}
      public function stop(): void {}
      public function suspend(): void {}
      public function resizeViewport(int $width, int $height): void {}
    };
    $this->config = new BattleConfig($party, $troop, settings: ['firstStrike' => 'normal']);
    $this->ui = new BattleScreen($this);
    $this->sceneStateContext = new SceneStateContext($this);
    $this->runState = new BattleRunState($this->sceneStateContext);
    $this->eventState = new BattleEventState($this->sceneStateContext);
    $this->victoryState = new BattleVictoryState($this->sceneStateContext);
    $this->defeatState = new \Ichiloto\Engine\Scenes\Battle\States\BattleDefeatState($this->sceneStateContext);
    new ReflectionProperty(BattleScene::class, 'battleEvents')->setValue($this, new BattleEventRunner(
      $troop, fn(): array => $this->partyBattlers, new EventSceneWorld(), $party, $lines));
    $game->sceneManager->currentScene = $this;
    $game->sceneManager->addScenes($this);
    $this->setState($this->runState);
    $this->start();
  }
}

function eventSceneEnemy(string $name, int $experience): Enemy
{
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => $name, 'level' => 1, 'stats' => new Stats(currentHp: 20, totalHp: 100), 'imagePath' => '',
    'image' => ['(o_o)'], 'position' => new Vector2(10, 2), 'rewards' => new BattleRewards($experience, 7, [])] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  return $enemy;
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, InputManager::class, EventManager::class, ConfigStore::class, Time::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, []);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  putSceneAudioConfig([]);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  Console::clear();
  $this->game = new EventSceneGame();
  $this->hero = new Character('Event Hero', 0, new Stats(currentHp: 100, totalHp: 100, speed: 10));
  $this->party = new Party();
  $this->party->addMember($this->hero);
  $this->lines = new EventSceneLines();
});

afterEach(function () {
  foreach ($this->statics as $class => $values) {
    foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function eventScenePage(array $commands, int $members): BattleEventPage
{
  return BattleEventPage::fromArray(['conditions' => ['enemy' => 0, 'enemyHpPercent' => 25], 'commands' => $commands],
    'scene test events[0]', $members, ScriptCommandCatalog::createEngineCatalog());
}

it('pauses the running battle for a page and resumes it with the engine untouched', function () {
  $rival = eventSceneEnemy('Rival', 30);
  $rival->stats->currentHp = 100;
  $troop = new Troop('Event trial', [$rival], [eventScenePage([['type' => 'text', 'text' => 'Enough.'], ['type' => 'concede', 'enemy' => 0]], 1)]);
  $scene = new EventBattleFixture($this->game, $troop, $this->party, $this->lines);
  $engine = $this->game->engine;
  expect($scene->state)->toBe($scene->runState);
  $rival->stats->currentHp = 20;

  expect($scene->runDueBattleEvents(1, 'action:1'))->toBeTrue()
    ->and($scene->state)->toBe($scene->eventState);
  $scene->update();
  expect($this->lines->lines)->toBe(['Enough.'])->and($engine->runs)->toBe(0)->and($rival->hasConceded)->toBeFalse();

  $this->lines->showing = false;
  $scene->update();
  $scene->update();
  expect($rival->hasConceded)->toBeTrue()->and($rival->isKnockedOut)->toBeFalse()
    ->and($scene->state)->toBe($scene->runState)->and($scene->result)->toBeNull();
  expect($scene->runDueBattleEvents(1, 'action:2'))->toBeFalse();
});

it('checks the opening turn as the battle starts', function () {
  $troop = new Troop('Event trial', [eventSceneEnemy('Rival', 30)], [BattleEventPage::fromArray(
    ['conditions' => ['turn' => 1], 'commands' => [['type' => 'text', 'text' => 'Begin.']]],
    'scene test events[0]', 1, ScriptCommandCatalog::createEngineCatalog())]);
  $scene = new EventBattleFixture($this->game, $troop, $this->party, $this->lines);

  expect($scene->state)->toBe($scene->eventState);
  $scene->update();
  expect($this->lines->lines)->toBe(['Begin.']);
});

it('ends the battle as an ordinary victory when a page asks for one', function () {
  $rival = eventSceneEnemy('Rival', 30);
  $guard = eventSceneEnemy('Guard', 12);
  $guard->stats->currentHp = 0;
  $troop = new Troop('Event trial', [$rival, $guard], [eventScenePage([['type' => 'end_battle', 'outcome' => 'victory']], 2)]);
  $scene = new EventBattleFixture($this->game, $troop, $this->party, $this->lines);
  $goldBefore = $this->party->accountBalance;

  $scene->runDueBattleEvents(1, 'action:1');
  $scene->update();

  expect($scene->state)->toBeInstanceOf(BattleVictoryState::class)
    ->and($scene->result?->outcome())->toBe('victory')
    ->and($scene->result?->rewards?->experiencePerMember)->toBe(42)
    ->and($this->party->accountBalance - $goldBefore)->toBe(14)
    ->and($rival->hasConceded)->toBeTrue()->and($rival->isKnockedOut)->toBeFalse()
    ->and($guard->isKnockedOut)->toBeTrue();
});

it('ends the battle as an escape or a defeat when a page asks for one', function (string $outcome, string $expected) {
  $troop = new Troop('Event trial', [eventSceneEnemy('Rival', 30)],
    [eventScenePage([['type' => 'end_battle', 'outcome' => $outcome]], 1)]);
  $scene = new EventBattleFixture($this->game, $troop, $this->party, $this->lines);

  $scene->runDueBattleEvents(1, 'action:1');
  $scene->update();

  expect($scene->result?->outcome())->toBe($expected)->and($scene->result?->rewards)->toBeNull();
})->with([['escape', 'escape'], ['defeat', 'defeat']]);
