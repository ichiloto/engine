<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleVictoryState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

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

it('reads the victory theme from the project config', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['victory' => 'crystal-fanfare']]]);

  expect(makeBareScene(BattleScene::class)->getVictoryMusic())->toBe('crystal-fanfare');
});

it('declares no victory theme when the config has none', function () {
  putSceneAudioConfig([]);

  expect(makeBareScene(BattleScene::class)->getVictoryMusic())->toBeNull();
});

it('lets a battle override the project victory theme through its settings', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['victory' => 'crystal-fanfare']]]);

  $scene = makeBareScene(BattleScene::class);
  $party = (new ReflectionClass(Ichiloto\Engine\Entities\Party::class))->newInstanceWithoutConstructor();
  $troop = (new ReflectionClass(Ichiloto\Engine\Entities\Troop::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(BattleScene::class, 'config')
    ->setValue($scene, new BattleConfig($party, $troop, settings: ['victory_bgm' => 'boss-fanfare']));

  expect($scene->getVictoryMusic())->toBe('boss-fanfare');
});

it('plays the victory theme when the party wins', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['victory' => 'crystal-fanfare']]]);
  [$game, $audioManager] = makeSceneAudioGame();
  $instanceProperty = new ReflectionProperty(AudioManager::class, 'instance');
  $instanceProperty->setValue(null, $audioManager);

  try {
    $scene = makeBareScene(BattleScene::class);
    new ReflectionProperty(BattleScene::class, 'sceneManager')
      ->setValue($scene, makeBareScene(SceneManager::class));

    $state = new BattleVictoryState(new SceneStateContext($scene));
    new ReflectionMethod(BattleVictoryState::class, 'playVictoryMusic')->invoke($state);

    expect($audioManager->calls)->toBe([['playBackgroundMusic', 'crystal-fanfare']]);
  } finally {
    $instanceProperty->setValue(null, null);
  }
});

it('leaves the battle music alone when no victory theme is configured', function () {
  putSceneAudioConfig([]);
  [$game, $audioManager] = makeSceneAudioGame();
  $instanceProperty = new ReflectionProperty(AudioManager::class, 'instance');
  $instanceProperty->setValue(null, $audioManager);

  try {
    $scene = makeBareScene(BattleScene::class);
    $state = new BattleVictoryState(new SceneStateContext($scene));
    new ReflectionMethod(BattleVictoryState::class, 'playVictoryMusic')->invoke($state);

    expect($audioManager->calls)->toBeEmpty();
  } finally {
    $instanceProperty->setValue(null, null);
  }
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

it('routes the global audio helpers through the current audio manager', function () {
  putSceneAudioConfig(['audio' => ['sounds' => ['cursor' => 'cursor-blip']]]);
  [, $audioManager] = makeSceneAudioGame();
  $instanceProperty = new ReflectionProperty(AudioManager::class, 'instance');
  $instanceProperty->setValue(null, $audioManager);

  try {
    play_sound(SystemSound::CURSOR);
    play_sound('slash');
    play_music('overworld-theme');
    stop_music();

    expect($audioManager->calls)->toBe([
      ['playSoundEffect', 'cursor-blip'],
      ['playSoundEffect', 'slash'],
      ['playBackgroundMusic', 'overworld-theme'],
      ['stopBackgroundMusic', null],
    ]);
  } finally {
    $instanceProperty->setValue(null, null);
  }
});

it('quietly ignores the global audio helpers when audio is not booted', function () {
  // No audio manager has been initialized in this process state.
  expect(function (): void {
    play_sound(SystemSound::CURSOR);
    play_music('overworld-theme');
    stop_music();
  })->not->toThrow(Throwable::class);
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
