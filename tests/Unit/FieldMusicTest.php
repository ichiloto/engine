<?php

use Ichiloto\Engine\Audio\CinematicMusicRequest;
use Ichiloto\Engine\Audio\FieldMusicCatalog;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;

afterEach(function () { ConfigStore::remove(FieldMusicCatalog::class); });

function musicRule(array $fields = []): array
{
  return array_replace([
    'id' => 'mission', 'track' => 'mission-theme', 'priority' => 10,
    'conditions' => [['type' => 'switch', 'name' => 'on_mission']],
  ], $fields);
}

function enterAudioMap(GameScene $scene, string $id, ?string $bgm, array $variants = []): void
{
  new ReflectionProperty($scene, 'currentMapId')->setValue($scene, $id);
  new ReflectionMethod(MapManager::class, 'applyMapBackgroundMusic')->invoke($scene->mapManager, $bgm, $variants);
}

it('keeps a scenario across differently scored interiors, exteriors and unscored maps', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  [$scene, , $audio] = makeFieldAudioScene();
  $scene->gameState->setSwitch('on_mission', true);
  foreach (['outside' => 'happy', 'inside' => 'briefing', 'hall' => null, 'outside-again' => 'happy'] as $id => $bgm) {
    enterAudioMap($scene, $id, $bgm);
    expect($scene->getBackgroundMusic())->toBe('mission-theme');
  }
  expect(array_unique(array_column($audio->calls, 1)))->toBe(['mission-theme']);
  $before = count($audio->calls);
  for ($i = 0; $i < 50; $i++) { $scene->refreshFieldMusic(); }
  expect(count($audio->calls))->toBe($before);
});

it('starts and ends a scenario without requiring a transfer or player movement', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  [$scene, , $audio] = makeFieldAudioScene();
  enterAudioMap($scene, 'outside', 'happy');
  $scene->gameState->setSwitch('on_mission', true);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('mission-theme');
  $scene->gameState->setSwitch('on_mission', false);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('happy');
});

it('resolves highest priority first with stable ties and exact map scopes', function () {
  $catalog = new FieldMusicCatalog([
    musicRule(['id' => 'campaign', 'priority' => 0, 'conditions' => [], 'track' => 'campaign']),
    musicRule(),
    musicRule(['id' => 'chamber', 'priority' => 20, 'maps' => ['temple'], 'track' => 'reverent']),
    musicRule(['id' => 'same-priority-later', 'priority' => 20, 'maps' => ['temple'], 'track' => 'wrong']),
  ]);
  $state = new GameState();
  expect($catalog->resolve('road', $state)->track)->toBe('campaign');
  $state->setSwitch('on_mission', true);
  expect($catalog->resolve('road', $state)->track)->toBe('mission-theme')
    ->and($catalog->resolve('temple', $state)->track)->toBe('reverent')
    ->and($catalog->resolve('temple/other', $state)->track)->toBe('mission-theme');
});

it('allows deliberate scenario silence and falls back to the next eligible scenario', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([
    musicRule(['id' => 'campaign', 'priority' => 0, 'track' => 'campaign', 'conditions' => []]),
    musicRule(['track' => null]),
  ]));
  [$scene, , $audio] = makeFieldAudioScene();
  $audio->playBackgroundMusic('old-theme');
  $scene->gameState->setSwitch('on_mission', true);
  enterAudioMap($scene, 'outside', 'happy');
  expect($audio->currentBackgroundMusic)->toBeNull();
  $scene->gameState->setSwitch('on_mission', false);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('campaign');
});

it('re-evaluates ordinary map variants without a transfer', function () {
  [$scene, , $audio] = makeFieldAudioScene();
  enterAudioMap($scene, 'town', 'happy', [[
    'track' => 'danger', 'conditions' => [['type' => 'event', 'name' => 'siege']],
  ]]);
  $scene->gameState->recordStoryEvent('siege');
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('danger');
});

it('does not inherit a mission theme as the default of an autoplay-off map', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  [$scene, , $audio] = makeFieldAudioScene();
  enterAudioMap($scene, 'road', 'happy');
  $scene->gameState->setSwitch('on_mission', true);
  $scene->refreshFieldMusic();
  enterAudioMap($scene, 'unscored-interior', null);
  $scene->gameState->setSwitch('on_mission', false);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('happy');
});

it('releases a mission to silence if no map ever supplied a default', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  [$scene, , $audio] = makeFieldAudioScene();
  $scene->gameState->setSwitch('on_mission', true);
  enterAudioMap($scene, 'unscored', null);
  $scene->gameState->setSwitch('on_mission', false);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBeNull();
});

it('preserves legacy event music when entering an autoplay-off map', function () {
  [$scene, , $audio] = makeFieldAudioScene();
  enterAudioMap($scene, 'road', 'happy');
  $audio->playBackgroundMusic('event-cue');
  enterAudioMap($scene, 'unscored', null);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('event-cue');
});

it('lets battle, victory and title own music and restores the live scenario on return', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  putSceneAudioConfig(['audio' => ['bgm' => ['battle' => 'battle', 'title' => 'title']]]);
  [$scene, , $audio, $manager] = makeFieldAudioScene();
  $scene->gameState->setSwitch('on_mission', true);
  enterAudioMap($scene, 'road', 'happy');
  $apply = new ReflectionMethod(SceneManager::class, 'applySceneBackgroundMusic');
  foreach ([BattleScene::class => 'battle', TitleScene::class => 'title'] as $class => $expected) {
    $other = makeBareScene($class);
    new ReflectionProperty($manager, 'currentScene')->setValue($manager, $other);
    $apply->invoke($manager, $other);
    $scene->refreshFieldMusic(force: true);
    expect($audio->currentBackgroundMusic)->toBe($expected);
    if ($class === BattleScene::class) { $audio->playBackgroundMusic('victory'); }
    new ReflectionProperty($manager, 'currentScene')->setValue($manager, $scene);
    $apply->invoke($manager, $scene);
    expect($audio->currentBackgroundMusic)->toBe('mission-theme');
  }
  new ReflectionProperty($manager, 'currentScene')->setValue($manager, makeBareScene(BattleScene::class));
  $audio->playBackgroundMusic('victory');
  $scene->gameState->setSwitch('on_mission', false);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('victory');
  new ReflectionProperty($manager, 'currentScene')->setValue($manager, $scene);
  $apply->invoke($manager, $scene);
  expect($audio->currentBackgroundMusic)->toBe('happy');
});

it('holds rest music through state changes and nested temporary scopes', function () {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  [$scene, , $audio] = makeFieldAudioScene();
  enterAudioMap($scene, 'inn', 'happy');
  $scene->holdFieldMusic();
  $scene->holdFieldMusic();
  $audio->playBackgroundMusic('rest');
  $scene->gameState->setSwitch('on_mission', true);
  $scene->refreshFieldMusic();
  $scene->releaseFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('rest');
  $scene->releaseFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('mission-theme');
});

it('preserves cinematic ownership across map transfers, fades and a battle return', function (string $outcome) {
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([musicRule()]));
  [$scene, , $audio] = makeFieldAudioScene();
  $scene->gameState->setSwitch('on_mission', true);
  enterAudioMap($scene, 'road', 'happy');
  $cue = $audio->beginCinematicMusic(new CinematicMusicRequest('sighting', false, fadeOut: 1, completionBehavior: 'restore_previous'));
  $cue->update(1);
  enterAudioMap($scene, 'chamber', 'crypt');
  expect($audio->currentBackgroundMusic)->toBe('sighting');
  $audio->playBackgroundMusic('battle');
  $scene->restoreBackgroundMusic();
  expect($audio->currentBackgroundMusic)->toBe('sighting');
  $scene->gameState->setSwitch('on_mission', false);
  $audio->finalizeCinematicMusic($outcome);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('sighting');
  $cue->updateFinalization(1);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('crypt');
})->with(['complete', 'skip', 'failure']);

it('preserves explicit cinematic stop or continue when the underlying field did not change', function (string $behavior, ?string $expected) {
  [$scene, , $audio] = makeFieldAudioScene();
  enterAudioMap($scene, 'road', 'happy');
  $audio->beginCinematicMusic(new CinematicMusicRequest('cue', completionBehavior: $behavior));
  $audio->finalizeCinematicMusic();
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe($expected);
})->with([['stop', null], ['continue', 'cue']]);

it('reconstructs conditional music from saved world state without saving playback objects', function () {
  $catalog = new FieldMusicCatalog([musicRule()]);
  $state = new GameState();
  $state->setSwitch('on_mission', true);
  $snapshot = $state->toArray();
  $loaded = GameState::fromArray($snapshot);
  expect($catalog->resolve('new-map', $loaded)->track)->toBe('mission-theme')
    ->and($loaded->toArray())->toBe($snapshot);
  $loaded->setSwitch('on_mission', false);
  expect($catalog->resolve('new-map', $loaded))->toBeNull();
});

it('rejects malformed rules rather than silently activating an override', function (array $entries) {
  expect(fn() => new FieldMusicCatalog($entries))->toThrow(InvalidArgumentException::class);
})->with([
  'not a list' => [[ 'wrong' => [] ]],
  'not a rule' => [[ 'wrong' ]],
  'missing identity' => [[['track' => 'music']]],
  'missing track' => [[['id' => 'broken']]],
  'blank track' => [[musicRule(['track' => ' '])]],
  'duplicate identity' => [[musicRule(), musicRule()]],
  'priority string' => [[musicRule(['priority' => '10'])]],
  'unknown field' => [[musicRule(['map' => 'temple'])]],
  'condition string' => [[musicRule(['conditions' => ['broken']])]],
  'unknown condition' => [[musicRule(['conditions' => [['type' => 'typo', 'name' => 'x']]])]],
  'blank condition name' => [[musicRule(['conditions' => [['type' => 'event', 'name' => ' ']]])]],
  'invalid quest status' => [[musicRule(['conditions' => [['type' => 'quest', 'name' => 'mission', 'status' => 'typo']]])]],
  'invalid maps' => [[musicRule(['maps' => 'temple'])]],
  'empty maps' => [[musicRule(['maps' => []])]],
  'wildcard maps' => [[musicRule(['maps' => ['temple/*']])]],
]);
