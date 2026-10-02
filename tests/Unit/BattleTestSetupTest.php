<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

function battleTestActor(string $id, int $currentExp = 0): ActorDefinition
{
  return ActorDefinition::fromArray(['id' => $id, 'name' => ucfirst($id), 'currentExp' => $currentExp, 'stats' => [
    'currentHp' => 40, 'currentMp' => 10, 'currentAp' => 3, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 3,
    'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2,
  ]]);
}

beforeEach(function () {
  $this->originalDirectory = getcwd();
  $this->originalConfig = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->root = sys_get_temp_dir() . '/' . uniqid('ichiloto-battle-test-', true);
  mkdir($this->root . '/assets/Data', 0777, true);
  file_put_contents($this->root . '/assets/Data/items.php', <<<'ITEMS'
  <?php
  use Ichiloto\Engine\Entities\Inventory\Items\Item;
  use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
  return [
    new Item('Potion', '', '', 10, id: 'item.potion'),
    new Item('Ether', '', '', 20, id: 'item.ether'),
    new Weapon('Sword', '', '/', 50, id: 'equipment.sword'),
  ];
  ITEMS);
  chdir($this->root);
  $this->items = new ItemStore();
  $this->actors = new ActorStore(definitions: [battleTestActor('hero'), battleTestActor('veteran', 100000)]);
});

afterEach(function () {
  chdir($this->originalDirectory);
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->originalConfig);
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('builds each member at its chosen level, below its starting level too, with health full', function () {
  $party = new BattleTestSetup([new BattleTestMember('hero', 10), new BattleTestMember('veteran', 1)])
    ->createParty($this->actors, $this->items);
  [$hero, $veteran] = $party->members->toArray();

  expect($hero->level)->toBe(10)
    ->and($veteran->level)->toBe(1)
    ->and($hero->stats->currentHp)->toBe($hero->stats->totalHp)
    ->and($hero->stats->currentMp)->toBe($hero->stats->totalMp);
});

it('equips what fits a slot and leaves off what does not', function () {
  $member = new BattleTestMember('hero', 1, ['Weapon' => 'equipment.sword', 'Body' => 'equipment.sword', 'Head' => 'item.potion']);
  $hero = new BattleTestSetup([$member])->createParty($this->actors, $this->items)->members->toArray()[0];
  $equipped = array_column(array_map(static fn($slot): array => ['slot' => $slot->name, 'item' => $slot->equipment?->id], $hero->equipment), 'item', 'slot');

  expect($equipped['Weapon'])->toBe('equipment.sword')
    ->and($equipped['Body'])->toBeNull()
    ->and($equipped['Head'])->toBeNull();
});

it('stocks 99 of every item that is not equipment, as RPG Maker\'s Battle Test does', function () {
  $party = new BattleTestSetup([new BattleTestMember('hero', 1)])->createParty($this->actors, $this->items);

  expect(array_column($party->inventory->all->toArray(), 'quantity', 'id'))
    ->toBe(['item.potion' => BattleTestSetup::ITEM_QUANTITY, 'item.ether' => BattleTestSetup::ITEM_QUANTITY]);
});

it('builds a fresh party every time, so one battle never carries into the next', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 5)]);
  $first = $setup->createParty($this->actors, $this->items);
  $first->members->toArray()[0]->addExperience(1000000);
  $first->members->toArray()[0]->stats->currentHp = 0;
  $second = $setup->createParty($this->actors, $this->items)->members->toArray()[0];

  expect($second)->not->toBe($first->members->toArray()[0])
    ->and($second->level)->toBe(5)
    ->and($second->stats->currentHp)->toBe($second->stats->totalHp);
});

it('reads a setup from a party and changes members within one to four', function () {
  $party = new BattleTestSetup([new BattleTestMember('hero', 7, ['Weapon' => 'equipment.sword'])])->createParty($this->actors, $this->items);
  $setup = BattleTestSetup::getFromParty($party);

  expect($setup->members[0]->actorId)->toBe('hero')
    ->and($setup->members[0]->level)->toBe(7)
    ->and($setup->members[0]->equipment['Weapon'])->toBe('equipment.sword')
    // The last member stays; a fifth is not added.
    ->and($setup->withMember(0, null)->members)->toHaveCount(1);
  $full = $setup;
  foreach (range(1, 5) as $index) {
    $full = $full->withMember($index, new BattleTestMember('veteran', 3));
  }
  expect($full->members)->toHaveCount(BattleTestSetup::MAX_MEMBERS)
    ->and($full->withMember(0, null)->members[0]->actorId)->toBe('veteran')
    ->and($full->members[0]->withActor('veteran')->equipment)->toBe([])
    ->and(fn() => new BattleTestSetup([]))->toThrow(InvalidArgumentException::class);
});

it('fights every battle with a fresh party and troop, so a beaten troop and a levelled party do not carry over', function () {
  mkdir($this->root . '/assets/Graphics/Enemies', 0777, true);
  file_put_contents($this->root . '/assets/Graphics/Enemies/rat.txt', "r\n");
  $rat = new Ichiloto\Engine\Entities\Enemies\Enemy('Rat', 1, new Ichiloto\Engine\Entities\Stats(currentHp: 30, totalHp: 30), 'rat',
    new Ichiloto\Engine\Battle\BattleRewards(50, 5, []), []);
  $enemies = (new ReflectionClass(Ichiloto\Engine\Util\Stores\EnemyStore::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(Ichiloto\Engine\Util\Stores\EnemyStore::class, 'enemies')->setValue($enemies, ['Rat' => $rat]);
  ConfigStore::put(Ichiloto\Engine\Util\Stores\EnemyStore::class, $enemies);
  ConfigStore::put(ActorStore::class, $this->actors);
  ConfigStore::put(ItemStore::class, $this->items);

  $battles = [];
  $manager = $this->getMockBuilder(Ichiloto\Engine\Scenes\SceneManager::class)->disableOriginalConstructor()
    ->onlyMethods(['loadBattleScene'])->getMock();
  $manager->method('loadBattleScene')->willReturnCallback(function ($party, $troop) use (&$battles): void {
    // What the fight starts with, before the battle changes it.
    $battles[] = [$party, $troop, $troop->members->toArray()[0]->stats->currentHp, $party->members->toArray()[0]->level];
    // The battle beats the troop and levels the party.
    foreach ($troop->members->toArray() as $enemy) { $enemy->stats->currentHp = 0; }
    $party->members->toArray()[0]->addExperience(1000000);
  });
  // Neither the constructor nor the destructor runs, so no game starts and the console is left alone.
  $game = new class extends Ichiloto\Engine\Core\Game {
    public function __construct() {}
    public function __destruct() {}
  };
  new ReflectionProperty(Ichiloto\Engine\Core\Game::class, 'sceneManager')->setValue($game, $manager);
  $scene = new class($game) extends Ichiloto\Engine\Scenes\Arena\ArenaScene {
    public function __construct(private Ichiloto\Engine\Core\Game $testGame) {}
    public function getGame(): Ichiloto\Engine\Core\Game { return $this->testGame; }
  };
  new ReflectionProperty(Ichiloto\Engine\Scenes\Arena\ArenaScene::class, 'troopData')
    ->setValue($scene, [['name' => 'Rats', 'enemies' => [['enemy' => 'Rat', 'position' => [1, 1]]]]]);
  new ReflectionProperty(Ichiloto\Engine\Scenes\Arena\ArenaScene::class, 'editor')->setValue($scene,
    new Ichiloto\Engine\Scenes\Arena\ArenaSetupEditor(new BattleTestSetup([new BattleTestMember('hero', 3)]), 1, ['hero'],
      static fn() => [], static fn() => [], static fn() => 99));
  $fight = new ReflectionMethod(Ichiloto\Engine\Scenes\Arena\ArenaScene::class, 'fight');

  $fight->invoke($scene, 0);
  $fight->invoke($scene, 0);

  [[$firstParty, $firstTroop], [$secondParty, $secondTroop, $secondHp, $secondLevel]] = $battles;
  expect($secondTroop)->not->toBe($firstTroop)
    ->and($secondTroop->members->toArray()[0])->not->toBe($firstTroop->members->toArray()[0])
    ->and($secondHp)->toBe(30)
    ->and($secondParty)->not->toBe($firstParty)
    ->and($secondLevel)->toBe(3);
});
