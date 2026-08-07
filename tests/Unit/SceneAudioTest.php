<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

/**
 * A ProjectConfig stand-in backed by a plain array.
 */
class SceneAudioConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $segments = explode('.', $path);
    $value = $this->values;

    foreach ($segments as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();

    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void
  {
  }
}

/**
 * An AudioManager that records calls instead of spawning player processes.
 */
class RecordingAudioManager extends AudioManager
{
  /** @var array<int, array{string, mixed}> */
  public array $calls = [];

  public function __construct(Game $game)
  {
    parent::__construct($game);
  }

  protected function createBackends(): array
  {
    return [];
  }

  public function playBackgroundMusic(string $path, bool $loop = true): void
  {
    $this->calls[] = ['playBackgroundMusic', $path];
  }

  public function stopBackgroundMusic(): void
  {
    $this->calls[] = ['stopBackgroundMusic', null];
  }

  public function playSoundEffect(string $path): void
  {
    $this->calls[] = ['playSoundEffect', $path];
  }
}

/**
 * Creates a Game whose audio manager records calls, without running the
 * heavyweight Game constructor.
 *
 * @return array{Game, RecordingAudioManager}
 */
function makeSceneAudioGame(): array
{
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $audioManager = new RecordingAudioManager($game);
  new ReflectionProperty(Game::class, 'audioManager')->setValue($game, $audioManager);

  return [$game, $audioManager];
}

/**
 * Instantiates a scene class without running its constructor.
 *
 * @template T of object
 * @param class-string<T> $sceneClass
 * @return T
 */
function makeBareScene(string $sceneClass): object
{
  return (new ReflectionClass($sceneClass))->newInstanceWithoutConstructor();
}

function putSceneAudioConfig(array $values): void
{
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub($values));
}

afterEach(function () {
  putSceneAudioConfig([]);
});

it('reads the title theme from the project config', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['title' => 'title-theme']]]);

  expect(makeBareScene(TitleScene::class)->getBackgroundMusic())->toBe('title-theme');
});

it('declares no title theme when the config has none', function () {
  putSceneAudioConfig([]);

  expect(makeBareScene(TitleScene::class)->getBackgroundMusic())->toBeNull();
});

it('treats a blank configured track as none', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['title' => '   ']]]);

  expect(makeBareScene(TitleScene::class)->getBackgroundMusic())->toBeNull();
});

it('reads the game-over theme from the project config', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['game_over' => 'requiem']]]);

  expect(makeBareScene(GameOverScene::class)->getBackgroundMusic())->toBe('requiem');
});

it('reads the battle theme from the project config', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['battle' => 'clash-of-steel']]]);

  expect(makeBareScene(BattleScene::class)->getBackgroundMusic())->toBe('clash-of-steel');
});

it('lets a battle override the project battle theme through its settings', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['battle' => 'clash-of-steel']]]);

  $scene = makeBareScene(BattleScene::class);
  $party = (new ReflectionClass(Ichiloto\Engine\Entities\Party::class))->newInstanceWithoutConstructor();
  $troop = (new ReflectionClass(Ichiloto\Engine\Entities\Troop::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(BattleScene::class, 'config')
    ->setValue($scene, new BattleConfig($party, $troop, settings: ['bgm' => 'boss-theme']));

  expect($scene->getBackgroundMusic())->toBe('boss-theme');
});

it('derives the game scene theme from the current map', function () {
  $scene = makeBareScene(GameScene::class);

  expect($scene->getBackgroundMusic())->toBeNull();

  $mapManager = makeBareScene(MapManager::class);
  new ReflectionProperty(MapManager::class, 'backgroundMusic')->setValue($mapManager, 'overworld-theme');
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $mapManager);

  expect($scene->getBackgroundMusic())->toBe('overworld-theme');
});

it('plays a declared map theme and remembers it', function () {
  [$game, $audioManager] = makeSceneAudioGame();
  $mapManager = makeBareScene(MapManager::class);
  new ReflectionProperty(MapManager::class, 'game')->setValue($mapManager, $game);

  $apply = new ReflectionMethod(MapManager::class, 'applyMapBackgroundMusic');
  $apply->invoke($mapManager, 'cave-theme');

  expect($mapManager->backgroundMusic)->toBe('cave-theme')
    ->and($audioManager->calls)->toBe([['playBackgroundMusic', 'cave-theme']]);
});

it('keeps the current music when a map declares no theme', function () {
  [$game, $audioManager] = makeSceneAudioGame();
  $mapManager = makeBareScene(MapManager::class);
  new ReflectionProperty(MapManager::class, 'game')->setValue($mapManager, $game);

  $apply = new ReflectionMethod(MapManager::class, 'applyMapBackgroundMusic');
  $apply->invoke($mapManager, null);

  expect($mapManager->backgroundMusic)->toBeNull()
    ->and($audioManager->calls)->toBeEmpty();
});

it('starts the incoming scene music on transition and silences scenes without one', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['title' => 'title-theme']]]);
  [$game, $audioManager] = makeSceneAudioGame();

  $sceneManager = makeBareScene(SceneManager::class);
  new ReflectionProperty(SceneManager::class, 'game')->setValue($sceneManager, $game);
  $apply = new ReflectionMethod(SceneManager::class, 'applySceneBackgroundMusic');

  $apply->invoke($sceneManager, makeBareScene(TitleScene::class));
  $apply->invoke($sceneManager, makeBareScene(GameScene::class));

  expect($audioManager->calls)->toBe([
    ['playBackgroundMusic', 'title-theme'],
    ['stopBackgroundMusic', null],
  ]);
});

it('lets a troop declare its own battle theme', function () {
  $troop = new Ichiloto\Engine\Entities\Troop('Boss Troop', backgroundMusic: '  boss-theme  ');

  expect($troop->backgroundMusic)->toBe('boss-theme')
    ->and(new Ichiloto\Engine\Entities\Troop('Mobs')->backgroundMusic)->toBeNull()
    ->and(new Ichiloto\Engine\Entities\Troop('Mobs', backgroundMusic: '   ')->backgroundMusic)->toBeNull();
});

it('plays configured system sounds through the sound effect pipeline', function () {
  putSceneAudioConfig(['audio' => ['sounds' => ['cursor' => 'cursor-blip']]]);
  [, $audioManager] = makeSceneAudioGame();

  $audioManager->playSystemSound(SystemSound::CURSOR);
  $audioManager->playSystemSound(SystemSound::CONFIRM);

  expect($audioManager->calls)->toBe([['playSoundEffect', 'cursor-blip']]);
});
