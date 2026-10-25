<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\EncounterEntry;
use Ichiloto\Engine\Field\EncounterManager;
use Ichiloto\Engine\Field\MapAreaCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;

/** An encounter manager that records the troops each encounter chose among instead of starting a battle. */
function makeAreaEncounterManager(): EncounterManager
{
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();

  return new class($scene) extends EncounterManager {
    /** @var list<array<string, int>> */
    public array $encounters = [];

    protected function startEncounter(array $weights): void
    {
      $this->resetCounter();
      $this->encounters[] = $weights;
    }
  };
}

/** A 6x3 map with a roost in its first two columns and a store in its last two. */
function makeAreaCatalog(): MapAreaCatalog
{
  return MapAreaCatalog::readMap([
    'areas' => [
      ['id' => 'roost', 'area' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 3]],
      ['id' => 'store', 'area' => ['cells' => [[4, 1], [5, 1]]]],
    ],
  ], array_fill(0, 3, array_fill(0, 6, ' ')));
}

it('reads the map areas an encounter entry is limited to', function () {
  expect(EncounterEntry::getFromValue(['weight' => 2, 'areas' => ['roost', 'store']])?->areas)->toBe(['roost', 'store'])
    ->and(EncounterEntry::getFromValue(['weight' => 2])?->areas)->toBe([])
    ->and(EncounterEntry::getFromValue(2)?->areas)->toBe([]);
});

it('drops an entry whose area scope cannot be read rather than meeting it map-wide', function (mixed $areas) {
  expect(EncounterEntry::getFromValue(['weight' => 2, 'areas' => $areas]))->toBeNull();
})->with([
  'empty' => [[]], 'text' => ['roost'], 'keyed' => [['a' => 'roost']], 'number' => [[3]],
  'not an area id' => [['Roost Pocket']], 'duplicate' => [['roost', 'roost']], 'null' => [null],
]);

it('meets area-limited troops only inside their areas and map-wide troops everywhere', function () {
  $manager = makeAreaEncounterManager();
  $manager->configure(['troops' => [
    'Roost Bats' => ['weight' => 3, 'areas' => ['roost']],
    'Store Rats' => ['weight' => 2, 'areas' => ['store', 'roost']],
    'Wanderer' => 1,
  ], 'rate' => 4, 'tiles' => 'any'], makeAreaCatalog());

  expect($manager->getTroopWeightsAt(new Vector2(1, 2)))->toBe(['Roost Bats' => 3, 'Store Rats' => 2, 'Wanderer' => 1])
    ->and($manager->getTroopWeightsAt(new Vector2(5, 1)))->toBe(['Store Rats' => 2, 'Wanderer' => 1])
    ->and($manager->getTroopWeightsAt(new Vector2(3, 1)))->toBe(['Wanderer' => 1])
    ->and($manager->getTroopWeightsAt(null))->toBe(['Wanderer' => 1]);
});

it('counts no step where no troop can be met, so pockets are quiet between them', function () {
  $manager = makeAreaEncounterManager();
  $manager->configure(['troops' => ['Roost Bats' => ['weight' => 1, 'areas' => ['roost']]], 'rate' => 2, 'tiles' => 'any'],
    makeAreaCatalog());

  foreach (range(1, 200) as $step) {
    $manager->registerStep(CollisionType::NONE, new Vector2(3, 1));
    $manager->registerStep(CollisionType::NONE, null);
  }
  expect($manager->encounters)->toBe([]);

  foreach (range(1, 20) as $step) {
    $manager->registerStep(CollisionType::NONE, new Vector2(0, 1));
  }
  expect($manager->encounters)->not->toBe([])
    ->and(array_unique(array_map(array_keys(...), $manager->encounters), SORT_REGULAR))->toBe([['Roost Bats']]);
});

it('never meets a troop limited to an area the map does not declare', function () {
  $manager = makeAreaEncounterManager();
  $manager->configure(['troops' => [
    'Lost Bats' => ['weight' => 5, 'areas' => ['roost', 'flooded-shaft']],
    'Wanderer' => 1,
  ], 'rate' => 1, 'tiles' => 'any'], makeAreaCatalog());

  expect($manager->getTroopWeightsAt(new Vector2(0, 0)))->toBe(['Wanderer' => 1]);

  $manager->configure(['troops' => ['Lost Bats' => ['weight' => 5, 'areas' => ['roost']]], 'rate' => 1, 'tiles' => 'any']);
  foreach (range(1, 20) as $step) {
    $manager->registerStep(CollisionType::NONE, new Vector2(0, 0));
  }
  expect($manager->encounters)->toBe([]);
});

it('weighs each step as the field counts it, in one table the field and the editor share', function () {
  $table = Ichiloto\Engine\Field\EncounterTable::readBlock(['troops' => [
    'Roost Bats' => ['weight' => 3, 'areas' => ['roost']],
    'Lost' => ['weight' => 1, 'areas' => ['nowhere']],
  ], 'rate' => 8, 'tiles' => 'any'], makeAreaCatalog());

  expect($table->averageStepsBetween)->toBe(8)
    ->and($table->hasEncounters)->toBeTrue()
    ->and($table->getStepWeight(CollisionType::NONE, new Vector2(0, 0)))->toBe(1.0)
    ->and($table->getStepWeight(CollisionType::ENCOUNTER, new Vector2(0, 0)))->toBe(2.0)
    ->and($table->getStepWeight(CollisionType::NONE, new Vector2(3, 0)))->toBe(0.0)
    ->and($table->ignored)->toBe(['Encounter "Lost" names a map area the map does not declare; it is never met.']);

  $dangerOnly = Ichiloto\Engine\Field\EncounterTable::readBlock(['troops' => ['Wolves' => 2]]);
  expect($dangerOnly->averageStepsBetween)->toBe(Ichiloto\Engine\Field\EncounterTable::DEFAULT_RATE)
    ->and($dangerOnly->getStepWeight(CollisionType::NONE, new Vector2(0, 0)))->toBe(0.0)
    ->and($dangerOnly->getStepWeight(CollisionType::ENCOUNTER, new Vector2(0, 0)))->toBe(1.0)
    ->and(Ichiloto\Engine\Field\EncounterTable::readBlock(null)->hasEncounters)->toBeFalse()
    ->and(Ichiloto\Engine\Field\EncounterTable::readBlock(['rate' => 5])->ignored)->toHaveCount(1);
});
