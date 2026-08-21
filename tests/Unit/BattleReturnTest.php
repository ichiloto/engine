<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Scenes\Arena\ArenaScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;

/**
 * Builds a scene manager holding the given scenes, without booting a game.
 *
 * @param SceneInterface[] $scenes The registered scenes.
 * @param class-string|null $sceneBeforeBattle The scene a battle came from.
 * @return SceneManager The manager.
 */
function makeSceneManagerFor(array $scenes, ?string $sceneBeforeBattle): SceneManager
{
  $manager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
  $list = new ItemList(SceneInterface::class);

  foreach ($scenes as $scene) {
    $list->add($scene);
  }

  new ReflectionProperty(SceneManager::class, 'scenes')->setValue($manager, $list);
  new ReflectionProperty(SceneManager::class, 'sceneBeforeBattle')->setValue($manager, $sceneBeforeBattle);

  return $manager;
}

/**
 * Asks the manager where a finished battle would go.
 *
 * @param SceneManager $manager The manager.
 * @return string The scene class.
 */
function whereBattleReturns(SceneManager $manager): string
{
  return new ReflectionMethod(SceneManager::class, 'sceneToReturnTo')->invoke($manager);
}

/**
 * Builds a scene of the given class without starting it.
 *
 * @param class-string $class The scene class.
 * @return SceneInterface The scene.
 */
function makeUnstartedScene(string $class): SceneInterface
{
  return new ReflectionClass($class)->newInstanceWithoutConstructor();
}

it('sends a battle back to the field it was started from', function () {
  $manager = makeSceneManagerFor(
    [makeUnstartedScene(GameScene::class), makeUnstartedScene(ArenaScene::class)],
    GameScene::class
  );

  expect(whereBattleReturns($manager))->toBe(GameScene::class);
});

it('sends a battle back to the arena it was started from', function () {
  $manager = makeSceneManagerFor(
    [makeUnstartedScene(GameScene::class), makeUnstartedScene(ArenaScene::class)],
    ArenaScene::class
  );

  // A developer testing a fight should land back on the troop list, not in a
  // map they never loaded.
  expect(whereBattleReturns($manager))->toBe(ArenaScene::class);
});

it('falls back to the field when nothing recorded where the battle came from', function () {
  $manager = makeSceneManagerFor([makeUnstartedScene(GameScene::class)], null);

  expect(whereBattleReturns($manager))->toBe(GameScene::class);
});

it('falls back to the field when the recorded scene is not registered', function () {
  $manager = makeSceneManagerFor([makeUnstartedScene(GameScene::class)], ArenaScene::class);

  // A game booted without the arena must not be sent to one.
  expect(whereBattleReturns($manager))->toBe(GameScene::class);
});
