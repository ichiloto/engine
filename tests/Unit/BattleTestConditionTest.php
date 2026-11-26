<?php

use Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Scenes\Arena\ArenaScene;
use Ichiloto\Engine\Scenes\Arena\BattleTestPartySetting;
use Ichiloto\Engine\UI\Navigation\ToolNavigationAction;
require_once __DIR__ . "/../Support/BattleTestPartyFixtures.php";
use Ichiloto\Engine\Scenes\Arena\ArenaSetupEditor;
use Ichiloto\Engine\Scenes\Arena\BattleTestChoices;
use Ichiloto\Engine\Scenes\Arena\BattleTestInventoryFocus;
use Ichiloto\Engine\Scenes\Arena\BattleTestItem;
use Ichiloto\Engine\Scenes\Arena\BattleTestLoadoutCatalog;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Scenes\Arena\BattleTestVital;
use Ichiloto\Engine\Scenes\Arena\BattleTestVitalUnit;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Scenes\Arena\TestTroopComposer;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\Presentation\BattleTestMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPager;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * A synthetic project: two items and a weapon, three states, and two actors
 * of 100 HP and 20 MP, one with a crisis ability active below 10% HP or
 * while it holds the `presence` state.
 */
function battleConditionActor(string $id, ?string $crisis = null, int $maximumHp = 100, int $maximumMp = 20): ActorDefinition
{
  return ActorDefinition::fromArray(['id' => $id, 'name' => ucfirst($id), 'currentExp' => 0,
    ...($crisis === null ? [] : ['crisisAbility' => $crisis]), 'stats' => [
    'currentHp' => $maximumHp, 'currentMp' => $maximumMp, 'currentAp' => 3,
    'totalHp' => $maximumHp, 'totalMp' => $maximumMp, 'totalAp' => 3,
    'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2,
  ]]);
}

beforeEach(function () {
  $this->originalDirectory = getcwd();
  $this->originalConfig = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->root = createTestDirectory('ichiloto-battle-condition-');
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
  file_put_contents($this->root . '/assets/Data/states.php', '<?php return ' . var_export([
    ['id' => 'presence', 'name' => 'Presence', 'disposition' => 'beneficial'],
    ['id' => 'poison', 'name' => 'Poison', 'persistsAfterBattle' => true],
    ['id' => 'stun', 'name' => 'Stun', 'durationTurns' => 2, 'preventsAction' => true],
  ], true) . ';');
  file_put_contents($this->root . '/assets/Data/crisis-abilities.php', '<?php return ' . var_export([
    ['id' => 'surge.hero', 'name' => 'Surge', 'hpBelowPercent' => 10.0, 'states' => ['presence'],
      'variants' => [['skill' => 'Test finisher']]],
    // A synthetic shape-shifter whose technique depends on story facts, and on a quest no test fight can set.
    ['id' => 'surge.shifter', 'name' => 'Surge', 'hpBelowPercent' => 10.0, 'states' => ['presence'], 'variants' => [
      ['skill' => 'Plain Cut', 'conditions' => [['type' => 'variable', 'name' => 'shifter_form', 'op' => '==', 'value' => 0]]],
      ['skill' => 'Beast Rend', 'conditions' => [['type' => 'variable', 'name' => 'shifter_form', 'op' => '==', 'value' => 'beast']]],
      ['skill' => 'Twin Howl', 'conditions' => [['type' => 'variable', 'name' => 'shifter_form', 'op' => '==', 'value' => 'twin'],
        ['type' => 'switch', 'name' => 'moon_up', 'value' => true]]],
      ['skill' => 'Relic Strike', 'conditions' => [['type' => 'quest', 'name' => 'relic-quest']]],
    ]],
  ], true) . ';');
  writeSkillRecords($this->root, ...array_map(static fn(string $name): SpecialSkill => new SpecialSkill($name, 'A synthetic technique.', '', 0, 0),
    ['Test finisher', 'Plain Cut', 'Beast Rend', 'Twin Howl', 'Relic Strike']));
  chdir($this->root);
  StateRegistry::reset();
  $this->items = new ItemStore();
  $this->actors = new ActorStore(definitions: [battleConditionActor('hero', 'surge.hero'), battleConditionActor('mage'),
    battleConditionActor('shifter', 'surge.shifter')]);
  $this->choices = new BattleTestChoices($this->actors, $this->items, BattleTestLoadoutCatalog::getProjectCatalog());
  ConfigStore::put(ActorStore::class, $this->actors);
  ConfigStore::put(ItemStore::class, $this->items);
});

afterEach(function () {
  chdir($this->originalDirectory);
  StateRegistry::reset();
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->originalConfig);
});

/** An arena scene that records each party it would fight with, instead of starting a battle. */
function battleConditionScene(object $test, BattleTestSetup $setup, array &$parties, array &$settings = []): ArenaScene
{
  if (! is_dir($test->root . '/assets/Graphics/Enemies')) {
    mkdir($test->root . '/assets/Graphics/Enemies', 0777, true);
  }
  file_put_contents($test->root . '/assets/Graphics/Enemies/rat.txt', "r\n");
  $rat = new Ichiloto\Engine\Entities\Enemies\Enemy('Rat', 1, new Ichiloto\Engine\Entities\Stats(currentHp: 30, totalHp: 30), 'rat',
    new Ichiloto\Engine\Battle\BattleRewards(50, 5, []), []);
  $enemies = (new ReflectionClass(EnemyStore::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(EnemyStore::class, 'enemies')->setValue($enemies, ['Rat' => $rat]);
  ConfigStore::put(EnemyStore::class, $enemies);
  file_put_contents($test->root . '/assets/Data/system.php', "<?php return ['title' => 'Test', 'currency' => [],
    'startingPositions' => ['player' => []], 'startingParty' => ['hero']];");

  // Neither the constructor nor a battle runs: the fight's party is recorded as it would start.
  $manager = new class($parties, $settings) extends SceneManager {
    public function __construct(private array &$recorded, private array &$recordedSettings) {}
    public function loadBattleScene(Party $party, \Ichiloto\Engine\Entities\Troop $troop, array $events = [], array $extraSettings = []): void
    {
      $this->recorded[] = $party;
      $this->recordedSettings[] = $extraSettings;
    }
  };
  $game = new class($setup) extends Game {
    public function __construct(BattleTestSetup $setup) { $this->options = [ArenaScene::SETUP_OPTION => $setup]; }
    public function __destruct() {}
  };
  new ReflectionProperty(Game::class, 'sceneManager')->setValue($game, $manager);
  $game->bindGameLoader(createTestGameLoader($game, ConfigStore::get(ActorStore::class), $test->items, $test->root . '/assets', $enemies));
  $scene = new class($game) extends ArenaScene {
    public function __construct(private Game $testGame) {}
    public function getGame(): Game { return $this->testGame; }
  };
  new ReflectionProperty(ArenaScene::class, 'troopData')->setValue($scene, [['name' => 'Rats', 'enemies' => [['enemy' => 'Rat', 'position' => [1, 1]]]]]);
  new ReflectionProperty(ArenaScene::class, 'troops')->setValue($scene, [new \Ichiloto\Engine\Entities\Troop('Rats', [])]);
  $editor = new ReflectionMethod(ArenaScene::class, 'createEditor')->invoke($scene);
  new ReflectionProperty(ArenaScene::class, 'editor')->setValue($scene, $editor);
  new ReflectionMethod(ArenaScene::class, 'refreshPreview')->invoke($scene);

  return $scene;
}

it('writes starting HP, MP, states and party items as project data and reads them back exactly', function () {
  $member = new BattleTestMember('hero', 4, hp: new BattleTestVital(9), mp: new BattleTestVital(3, BattleTestVitalUnit::POINTS),
    states: ['presence', 'poison']);
  $setup = new BattleTestSetup([$member], 'arena.lake', inventory: [new BattleTestItem('item.ether', 3)]);

  expect($member->toArray())->toBe(['actor' => 'hero', 'level' => 4, 'hp' => ['percent' => 9], 'mp' => ['points' => 3],
      'states' => ['presence', 'poison']])
    ->and(BattleTestMember::fromArray($member->toArray()))->toEqual($member)
    ->and($setup->toArray())->toBe(['members' => [$member->toArray()], 'arena' => 'arena.lake',
      'inventory' => [['item' => 'item.ether', 'quantity' => 3]]])
    ->and(BattleTestSetup::fromArray($setup->toArray()))->toEqual($setup)
    // An empty list of items is the party holding nothing, kept apart from the default stock.
    ->and(BattleTestSetup::fromArray(['members' => [['actor' => 'hero', 'level' => 1]], 'inventory' => []])->inventory)->toBe([])
    ->and(BattleTestSetup::fromArray(['members' => [['actor' => 'hero', 'level' => 1]]])->inventory)->toBeNull()
    ->and(new BattleTestMember('hero', 1)->withHp(BattleTestVital::createFull())->toArray())->toBe(['actor' => 'hero', 'level' => 1]);

  // The project's entry validates and forwards every key a setup writes, and keeps items without a party.
  $entry = ['troop' => 'Rats', ...$setup->toArray()];
  $itemsOnly = ['troop' => 'Rats', 'inventory' => [['item' => 'item.potion', 'quantity' => 2]]];
  expect(ProjectBattleTest::fromArray($entry)->toArray())->toBe($entry)
    ->and(ProjectBattleTest::fromArray($entry)->setup?->inventory)->toEqual([new BattleTestItem('item.ether', 3)])
    ->and(ProjectBattleTest::fromArray($itemsOnly)->toArray())->toBe($itemsOnly)
    ->and(ProjectBattleTest::fromArray($itemsOnly)->createSetup($this->actors, ['hero'])->inventory)
    ->toEqual([new BattleTestItem('item.potion', 2)]);
});

it('round trips and bounds test-only AP using the current opted-in actor cap', function () {
  $authored = battleConditionActor('hero')->data();
  $authored['stats']['currentAp'] = 5;
  $authored['stats']['totalAp'] = 60;
  $actors = new ActorStore(definitions: [ActorDefinition::fromArray($authored)],
    actionPointPolicy: new \Ichiloto\Engine\Battle\ActionPointPolicy(10));
  $choices = new BattleTestChoices($actors, $this->items, BattleTestLoadoutCatalog::getProjectCatalog());
  $entry = ['members' => [['actor' => 'hero', 'level' => 1, 'ap' => ['points' => 30]]]];
  $setup = ProjectBattleTest::fromArray($entry)->createSetup($actors);
  $member = $setup->members[0];
  expect(ProjectBattleTest::fromArray($entry)->toArray())->toBe($entry)
    ->and($member->withLevel(2)->withEquipment('Weapon', null)->toArray()['ap'])->toBe(['points' => 30])
    ->and($member->withActor('another')->toArray())->not->toHaveKey('ap')
    ->and($choices->describeStartingVitals($setup, 0)['ap'])->toBe([
      'maximum' => 60,
      'default' => ['points' => 5],
      'choices' => [
        ['name' => 'Authored balance (5)', 'value' => null],
        ['name' => 'Full', 'value' => ['percent' => 100]],
        ['name' => 'Empty', 'value' => ['percent' => 0]],
        ['name' => 'In percent: 50%', 'value' => ['percent' => 50]],
      ],
    ])
    ->and($setup->getProblems($this->actors, $this->items))->toContain(
      'Member 1 (hero): starting AP requires the project to enable battle action points.')
    ->and($this->choices->describeStartingVitals($setup, 0))->not->toHaveKey('ap');
  foreach ([[null, 5], [['points' => 30], 30], [['percent' => 100], 60], [['percent' => 0], 0]] as [$override, $expected]) {
    $fixture = BattleTestSetup::fromArray(['members' => [['actor' => 'hero', 'level' => 1,
      ...($override === null ? [] : ['ap' => $override])]]]);
    expect($fixture->createParty($actors, $this->items)->members->toArray()[0]->stats->currentAp)->toBe($expected);
  }
  $beyondCap = BattleTestSetup::fromArray(['members' => [['actor' => 'hero', 'level' => 1, 'ap' => ['points' => 61]]]]);
  expect(fn () => $beyondCap->createParty($actors, $this->items))->toThrow(InvalidArgumentException::class, '61 AP is beyond its maximum, 60.')
    ->and($actors->require('hero', 'the original actor')->createCharacter()->stats->currentAp)->toBe(5);
});

it('sets opted-in AP from the Arena setup row and rebuilds it on each battle entry', function () {
  $authored = battleConditionActor('hero')->data();
  $authored['stats']['currentAp'] = 5;
  $authored['stats']['totalAp'] = 60;
  $actors = new ActorStore(definitions: [ActorDefinition::fromArray($authored)],
    actionPointPolicy: new \Ichiloto\Engine\Battle\ActionPointPolicy(10));
  ConfigStore::put(ActorStore::class, $actors);
  $parties = [];
  $scene = battleConditionScene($this, new BattleTestSetup([new BattleTestMember('hero', 1)]), $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  $editor->movePane(1);
  $editor->confirm();
  expect(array_slice($editor->fields, 0, 6))->toBe(['actor', 'level', 'hp', 'mp', 'ap', 'states'])
    ->and($scene->listPartyStats()[0][1]['AP'])->toBe('5/60');

  $editor->moveVertical(4);
  $editor->movePage(1, 16);
  expect($editor->setup->members[0]->toArray()['ap'])->toBe(['points' => 15])
    ->and($scene->describeList()->rows[4])->toBe(['AP', ['< 15 >']]);
  $editor->confirm();
  $editor->moveVertical(-10);
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->setup->members[0]->toArray()['ap'])->toBe(['percent' => 100]);
  new ReflectionMethod(ArenaScene::class, 'refreshPreview')->invoke($scene);
  expect($scene->listPartyStats()[0][1]['AP'])->toBe('60/60');

  $fight = new ReflectionMethod(ArenaScene::class, 'fight');
  $fight->invoke($scene, 0);
  $parties[0]->members->toArray()[0]->stats->currentAp = 13;
  $fight->invoke($scene, 0);
  expect($parties[1]->members->toArray()[0]->stats->currentAp)->toBe(60)
    ->and($actors->require('hero', 'the original actor')->createCharacter()->stats->currentAp)->toBe(5);
});

it('refuses malformed starting conditions and items instead of ignoring them', function (array $data, string $message) {
  expect(fn() => isset($data['members']) ? BattleTestSetup::fromArray($data) : BattleTestMember::fromArray($data))
    ->toThrow(InvalidArgumentException::class, $message);
})->with([
  'hp as text' => [['actor' => 'hero', 'level' => 1, 'hp' => '9%'], 'percent or points'],
  'hp in two units' => [['actor' => 'hero', 'level' => 1, 'hp' => ['percent' => 9, 'points' => 2]], 'percent or points'],
  'percent over 100' => [['actor' => 'hero', 'level' => 1, 'mp' => ['percent' => 101]], '0 to 100 percent'],
  'states repeated' => [['actor' => 'hero', 'level' => 1, 'states' => ['stun', 'stun']], 'unique'],
  'items not a list' => [['members' => [['actor' => 'hero', 'level' => 1]], 'inventory' => ['item.potion' => 3]], 'list of items'],
  'item listed twice' => [['members' => [['actor' => 'hero', 'level' => 1]],
    'inventory' => [['item' => 'item.potion', 'quantity' => 1], ['item' => 'item.potion', 'quantity' => 2]]], 'more than once'],
  'no quantity' => [['members' => [['actor' => 'hero', 'level' => 1]], 'inventory' => [['item' => 'item.potion', 'quantity' => 0]]], 'at least 1'],
]);

it('reads typed starting amounts and converts them by the member\'s maximum', function () {
  expect(BattleTestVital::parse('9%'))->toEqual(new BattleTestVital(9))
    ->and(BattleTestVital::parse(' 29 '))->toEqual(new BattleTestVital(29, BattleTestVitalUnit::POINTS))
    ->and(fn() => BattleTestVital::parse('half'))->toThrow(InvalidArgumentException::class)
    ->and(new BattleTestVital(9)->resolvePoints(325))->toBe(29)
    ->and(new BattleTestVital(1)->resolvePoints(20))->toBe(1)
    ->and(new BattleTestVital(0)->resolvePoints(325))->toBe(0)
    ->and(new BattleTestVital(9)->convert(325))->toEqual(new BattleTestVital(29, BattleTestVitalUnit::POINTS))
    ->and(new BattleTestVital(29, BattleTestVitalUnit::POINTS)->convert(325))->toEqual(new BattleTestVital(8));
});

it('starts each member with its HP, MP and states on the real party, and holds exactly the items set', function () {
  $setup = new BattleTestSetup([
    new BattleTestMember('hero', 1, hp: new BattleTestVital(25), mp: new BattleTestVital(0), states: ['poison', 'stun']),
    new BattleTestMember('mage', 1, hp: new BattleTestVital(7, BattleTestVitalUnit::POINTS)),
  ], inventory: [new BattleTestItem('item.ether', 3)]);
  [$hero, $mage] = $setup->createParty($this->actors, $this->items)->members->toArray();
  $party = $setup->createParty($this->actors, $this->items);

  expect([$hero->stats->currentHp, $hero->stats->currentMp, $mage->stats->currentHp, $mage->stats->currentMp])->toBe([25, 0, 7, 20])
    ->and($hero->hasState('poison'))->toBeTrue()->and($hero->hasState('stun'))->toBeTrue()
    ->and($hero->getActionBlockingState()?->id)->toBe('stun')
    ->and($mage->states)->toBe([])
    ->and(array_column($party->inventory->all->toArray(), 'quantity', 'id'))->toBe(['item.ether' => 3])
    // Set to nothing, the party holds nothing; left unset, it holds the default stock as before.
    ->and($setup->withInventory([])->createParty($this->actors, $this->items)->inventory->all->toArray())->toBe([])
    ->and(array_column($setup->withInventory(null)->createParty($this->actors, $this->items)->inventory->all->toArray(), 'quantity', 'id'))
    ->toBe(['item.potion' => BattleTestSetup::ITEM_QUANTITY, 'item.ether' => BattleTestSetup::ITEM_QUANTITY]);
});

it('names every problem with a starting condition or the items before building the party', function () {
  $setup = new BattleTestSetup([
    new BattleTestMember('hero', 1, hp: new BattleTestVital(0), mp: new BattleTestVital(500, BattleTestVitalUnit::POINTS), states: ['frozen']),
    new BattleTestMember('mage', 1, hp: new BattleTestVital(0, BattleTestVitalUnit::POINTS)),
  ], inventory: [new BattleTestItem('item.missing', 1), new BattleTestItem('equipment.sword', 1), new BattleTestItem('item.potion', 100)]);

  expect($setup->getProblems($this->actors, $this->items))->toBe([
    'Member 1 (hero): the project has no state frozen.',
    'Every member starts knocked out, so there is no fight; give one member some HP.',
    'Items: the project has no item item.missing.',
    'Items: Sword is equipment, which members wear; set it on a member instead.',
    'Items: the party holds at most 99 Potion, not 100.',
  ])->and(new BattleTestSetup([new BattleTestMember('hero', 1, mp: new BattleTestVital(500, BattleTestVitalUnit::POINTS))])
    ->getProblems($this->actors, $this->items))->toBe(['Member 1 (hero): 500 MP is beyond its maximum, 20.'])
    ->and(fn() => $setup->createParty($this->actors, $this->items))->toThrow(InvalidArgumentException::class);
});

it('keeps the items and starting conditions through every rebuild of the setup', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1, hp: new BattleTestVital(9), states: ['presence'])],
    inventory: [new BattleTestItem('item.potion', 3)]);
  $rebuilt = $setup->withMember(1, new BattleTestMember('mage', 2))->withArena('arena.lake')->withBattleSize(2)->withMember(1, null);

  expect($rebuilt->inventory)->toEqual([new BattleTestItem('item.potion', 3)])
    ->and($setup->withInventory([])->withArena('arena.road')->withMember(0, $setup->members[0]->withLevel(5))->inventory)->toBe([])
    ->and($rebuilt->members[0]->hp)->toEqual(new BattleTestVital(9))
    ->and($rebuilt->members[0]->withLevel(3)->withEquipment('Weapon', 'equipment.sword')->withActor('mage')->states)->toBe(['presence']);
});

it('offers the states, starting amounts and items the setup would accept, from the project\'s own definitions', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1), new BattleTestMember('mage', 1, hp: new BattleTestVital(30))]);

  expect($this->choices->getConditionChoices($setup, 0, 'states'))->toBe([['id' => null, 'name' => '(None)'],
      ['id' => 'presence', 'name' => 'Presence'], ['id' => 'poison', 'name' => 'Poison'], ['id' => 'stun', 'name' => 'Stun']])
    // The crisis threshold comes from the actor's ability: the highest whole percent below 10%.
    ->and($this->choices->getConditionChoices($setup, 0, 'hp'))->toBe([['id' => null, 'name' => 'Full'],
      ['id' => '9%', 'name' => 'Below Surge: 9%'], ['id' => '0%', 'name' => 'Knocked out'], ['id' => '100', 'name' => 'In points: 100 of 100']])
    ->and($this->choices->getConditionChoices($setup, 1, 'hp'))->toBe([['id' => null, 'name' => 'Full'],
      ['id' => '0%', 'name' => 'Knocked out'], ['id' => '30', 'name' => 'In points: 30 of 100']])
    ->and(array_column($this->choices->getConditionChoices($setup, 0, 'mp'), 'name'))->toBe(['Full', 'Empty', 'In points: 20 of 20'])
    ->and($this->choices->getVitalMaximum($setup, 0, 'mp'))->toBe(20)
    ->and($this->choices->getItemChoices())->toBe([['id' => 'item.potion', 'name' => 'Potion', 'maxQuantity' => 99],
      ['id' => 'item.ether', 'name' => 'Ether', 'maxQuantity' => 99]])
    ->and($this->choices->getDefaultStock())->toEqual([new BattleTestItem('item.potion', 99), new BattleTestItem('item.ether', 99)]);
});

it('offers a below-threshold HP preset even when only exact points can activate the technique', function () {
  $catalogue = $this->root . '/assets/Data/crisis-abilities.php';
  $entries = require $catalogue;
  $entries[] = ['id' => 'surge.fraction', 'name' => 'Surge', 'hpBelowPercent' => 0.5,
    'states' => ['presence'], 'variants' => [['skill' => 'Test finisher']]];
  file_put_contents($catalogue, '<?php return ' . var_export($entries, true) . ';');
  $actors = new ActorStore(definitions: [
    battleConditionActor('ordinary', 'surge.hero', 376),
    battleConditionActor('fraction', 'surge.fraction', 600),
    battleConditionActor('floor', 'surge.fraction', 200),
  ]);
  $choices = new BattleTestChoices($actors, $this->items, BattleTestLoadoutCatalog::getProjectCatalog());

  foreach (['ordinary' => '9%', 'fraction' => '1', 'floor' => null] as $actor => $expected) {
    $member = new BattleTestMember($actor, 1, mp: new BattleTestVital(0));
    $setup = new BattleTestSetup([$member]);
    $preset = array_find($choices->getConditionChoices($setup, 0, 'hp'),
      static fn(array $choice): bool => str_starts_with($choice['name'], 'Below Surge:'));
    expect($preset['id'] ?? null)->toBe($expected);
    $vitals = $choices->describeStartingVitals($setup, 0);
    if ($expected !== null) {
      $amount = BattleTestVital::parse($expected);
      expect($vitals['hp']['choices'])->toContain(['name' => $preset['name'], 'value' => $amount->toArray()]);
      $party = $setup->withMember(0, $member->withHp($amount))->createParty($actors, $this->items);
      expect($party->leader->crisisAbility->isActive($party->leader))->toBeTrue()
        ->and($party->leader->stats->currentMp)->toBe(0);
    }
    $withPresence = $setup->withMember(0, $member->withStates(['presence']))->createParty($actors, $this->items);
    expect($withPresence->leader->crisisAbility->isActive($withPresence->leader))->toBeTrue()
      ->and($withPresence->leader->stats->currentHp)->toBe($vitals['hp']['maximum']);
  }
});

it('preserves nonzero starting vitals in unit alternatives', function () {
  $actors = new ActorStore(definitions: [battleConditionActor('hero', 'surge.hero', 600, 300)]);
  $choices = new BattleTestChoices($actors, $this->items, BattleTestLoadoutCatalog::getProjectCatalog());
  $firstPoint = new BattleTestVital(1, BattleTestVitalUnit::POINTS);
  $member = new BattleTestMember('hero', 1, hp: $firstPoint, mp: $firstPoint);
  $setup = new BattleTestSetup([$member]);
  $vitals = $choices->describeStartingVitals($setup, 0);

  foreach (['hp' => 'Knocked out', 'mp' => 'Empty'] as $field => $zeroLabel) {
    $arenaChoices = $choices->getConditionChoices($setup, 0, $field);
    expect(array_column($arenaChoices, 'name'))->not->toContain('In percent: 0%')
      ->and($arenaChoices)->toContain(['id' => '0%', 'name' => $zeroLabel])
      ->and($vitals[$field]['choices'])->toContain(['name' => $zeroLabel, 'value' => ['percent' => 0]])
      ->and(array_column($vitals[$field]['choices'], 'name'))->not->toContain('In percent: 0%');
  }

  $sixPoints = new BattleTestVital(6, BattleTestVitalUnit::POINTS);
  $representable = $setup->withMember(0, $member->withHp($sixPoints));
  expect($choices->getConditionChoices($representable, 0, 'hp'))->toContain(['id' => '1%', 'name' => 'In percent: 1%'])
    ->and($choices->describeStartingVitals($representable, 0)['hp']['choices'])
    ->toContain(['name' => 'In percent: 1%', 'value' => ['percent' => 1]]);
});

it('makes Overflow available in the battle for a member set below its threshold or given Presence', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1), new BattleTestMember('mage', 1)]);
  $threshold = $this->choices->getConditionChoices($setup, 0, 'hp')[1];
  $cases = [
    'threshold' => $setup->withMember(0, $setup->members[0]->withHp(BattleTestVital::parse($threshold['id']))),
    'presence' => $setup->withMember(0, $setup->members[0]->withStates(['presence'])),
    'both' => $setup->withMember(0, $setup->members[0]->withHp(BattleTestVital::parse($threshold['id']))->withStates(['presence'])),
    'neither' => $setup,
  ];
  $parties = [];
  foreach ($cases as $case) {
    $scene = battleConditionScene($this, $case, $parties);
    new ReflectionMethod(ArenaScene::class, 'fight')->invoke($scene, 0);
  }

  foreach (array_combine(array_keys($cases), $parties) as $case => $party) {
    $hero = $party->members->toArray()[0];
    $option = BattleCommandCatalog::getAvailableCrisisOption($hero, $party);
    $attack = array_column(BattleCommandCatalog::buildOptions($hero, $party, BattleCommandType::ATTACK), 'label');
    if ($case === 'neither') {
      expect($option)->toBeNull()->and($attack)->toBe(['Attack']);
      continue;
    }
    expect($option?->action)->toBeInstanceOf(CrisisSkillBattleAction::class)
      ->and($attack)->toBe(['Attack', 'Test finisher'])
      ->and($hero->crisisAbility?->isActive($hero))->toBeTrue();
  }
  expect($parties[0]->members->toArray()[0]->stats->currentHp)->toBe(9);
});

it('rebuilds the starting items and conditions for every fight, a listed troop or a composed one', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1, hp: new BattleTestVital(50), states: ['poison'])],
    inventory: [new BattleTestItem('item.potion', 3)]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  $composer = new TestTroopComposer(['Rat'], static fn() => [3, 4]);
  $editor = new ArenaSetupEditor($editor->setup, 1, ['hero'], static fn() => [], static fn() => [], static fn() => 99, composer: $composer);
  $composer->confirm();
  $composer->confirm();
  new ReflectionProperty(ArenaScene::class, 'editor')->setValue($scene, $editor);

  $spend = static function (Party $party): void {
    $hero = $party->members->toArray()[0];
    $party->removeItems(...ConfigStore::get(ItemStore::class)->instantiate('item.potion', 2));
    $hero->stats->currentHp = 1;
    $hero->removeState('poison');
  };
  new ReflectionMethod(ArenaScene::class, 'fight')->invoke($scene, 0);
  $spend($parties[0]);
  new ReflectionMethod(ArenaScene::class, 'fightTestTroop')->invoke($scene);
  $spend($parties[1]);
  new ReflectionMethod(ArenaScene::class, 'fight')->invoke($scene, 0);

  foreach ($parties as $index => $party) {
    $hero = $party->members->toArray()[0];
    $quantities = array_column($party->inventory->all->toArray(), 'quantity', 'id');
    expect($quantities)->toBe($index === 2 ? ['item.potion' => 3] : ['item.potion' => 1]);
  }
  $fresh = $parties[2]->members->toArray()[0];
  expect(count($parties))->toBe(3)->and($fresh->stats->currentHp)->toBe(50)->and($fresh->hasState('poison'))->toBeTrue();
});

it('changes the battle test frontline from one to eight through party management', function () {
  $members = array_fill(0, Party::MAX_BATTLE_SIZE, new BattleTestMember('hero', 1));
  $setup = new BattleTestSetup($members, inventory: [new BattleTestItem('item.potion', 3)]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);

  selectBattleTestPartySetting($editor, BattleTestPartySetting::FRONTLINE);
  $editor->moveHorizontal(-1);
  $editor->moveHorizontal(-1);
  $editor->moveHorizontal(-1);
  $fight = new ReflectionMethod(ArenaScene::class, 'fight');
  foreach (range(1, Party::MAX_BATTLE_SIZE) as $count) {
    expect($editor->focus)->toBe(ArenaSetupEditor::MANAGEMENT)->and($editor->setup->battleSize)->toBe($count);
    $fight->invoke($scene, 0);
    $party = $parties[array_key_last($parties)];
    expect(count($party->battlers))->toBe($count)->and(count($party->members))->toBe(Party::MAX_BATTLE_SIZE)
      ->and($party->inventory->getQuantityById('item.potion'))->toBe(3);
    $editor->moveHorizontal(1);
  }
  expect($editor->setup->battleSize)->toBe(Party::MAX_BATTLE_SIZE)->and($setup->battleSize)->toBe(Party::BATTLE_SIZE);

  $list = $scene->describeList();
  expect($list->hints)->toContain([ToolNavigationAction::QUIT->value, 'Quit']);
  $title = new ReflectionMethod(ArenaScene::class, 'getPartyTitle')->invoke($scene);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
  $canvas = BattleTestMenuPresentation::compose($theme, $list, false, $scene->listPartyStats(), Party::MAX_BATTLE_SIZE - 1,
    null, null, null, false, partyTitle: $title);
  $text = implode(' ', array_map(static fn($layer): string => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
  $hintLayers = array_filter($canvas->textLayers, static fn($layer): bool => str_starts_with($layer->id, 'battle-test-hints'));
  $hintText = implode(' ', array_map(static fn($layer): string => implode('', array_column($layer->runs, 'text')), $hintLayers));
  expect($title)->toBe('Party · Frontline limit 8')->and($text)->toContain($title)
    ->and($hintText)->toContain('Frontline')
    ->and(array_column($canvas->textLayers, 'id'))->toContain('battle-test-members-battle-test-member-7-text');

  $editor->movePane(-1);
  $unfocused = BattleTestMenuPresentation::compose($theme, $scene->describeList(), true, $scene->listPartyStats(), null,
    null, null, null, false, partyTitle: $title, partyAnchor: Party::MAX_BATTLE_SIZE - 1);
  expect(array_column($unfocused->textLayers, 'id'))->toContain('battle-test-members-battle-test-member-7-text')
    ->and(array_column($unfocused->images, 'id'))->not->toContain('battle-test-members-battle-test-member-7-selected');
});

it('removes Items from the Tab cycle and edits quantities, additions, removal and reset through party management', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1)]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  $items = $editor->inventoryEditor;

  $editor->movePane(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::PARTY);
  $editor->movePane(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::TROOPS);
  selectBattleTestPartySetting($editor, BattleTestPartySetting::ITEMS);
  $editor->confirm();
  $list = $scene->describeList();
  expect($editor->focus)->toBe(ArenaSetupEditor::ITEMS)
    ->and($list->title)->toBe('Items (default stock)')
    ->and($list->rows)->toBe([['Start with no items', ['']], ['Potion', ['x99']], ['Ether', ['x99']]])
    ->and($list->hints)->toContain(['confirm', 'Start'], [ArenaScene::PANE_PREVIOUS_ACTION, 'Cycle panels', ArenaScene::PANE_NEXT_ACTION], [ToolNavigationAction::QUIT->value, 'Quit'])
    ->and($list->requiredHintActions)->toBe([ArenaScene::PANE_PREVIOUS_ACTION, ArenaScene::PANE_NEXT_ACTION,
      ToolNavigationAction::CANCEL->value, ToolNavigationAction::QUIT->value]);

  // Ether: down to its row, confirm, down one, left ten.
  $editor->moveVertical(2);
  $editor->confirm();
  $editor->moveVertical(1);
  $editor->moveHorizontal(-1);
  expect($items->focus)->toBe(BattleTestInventoryFocus::QUANTITY)
    ->and($scene->describeList()->rows)->toBe([['Quantity', ['x88']]])
    ->and($scene->describeList()->hints)->toContain(['up', 'Quantity', 'down'], ['left', 'By 10', 'right'], ['confirm', 'Set']);
  // Shift+Tab cycles the visible panels even during quantity editing, preserving the unconfirmed quantity.
  $editor->movePane(-1);
  expect($editor->focus)->toBe(ArenaSetupEditor::PARTY)
    ->and($editor->listFocus)->toBe(ArenaSetupEditor::ITEMS)
    ->and($items->focus)->toBe(BattleTestInventoryFocus::QUANTITY);
  $editor->movePane(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::ITEMS)
    ->and($scene->describeList()->hints)->toContain([ToolNavigationAction::CANCEL->value, 'Back']);
  $editor->confirm();
  expect($editor->setup->inventory)->toEqual([new BattleTestItem('item.potion', 99), new BattleTestItem('item.ether', 88)]);

  // Potion down to none removes it; cancel before confirming changes nothing.
  $editor->moveVertical(-1);
  $editor->confirm();
  $editor->moveHorizontal(-1);
  $editor->cancel();
  expect($editor->setup->inventory[0]->quantity)->toBe(99)
    ->and($editor->focus)->toBe(ArenaSetupEditor::ITEMS)
    ->and($items->focus)->toBe(BattleTestInventoryFocus::ENTRIES);
  $editor->confirm();
  foreach (range(1, 10) as $step) { $editor->moveHorizontal(-1); }
  expect($scene->describeList()->rows)->toBe([['Remove from the items', ['x0']]])
    ->and($scene->describeList()->hints)->toContain(['confirm', 'Remove']);
  $editor->confirm();
  expect($editor->setup->inventory)->toEqual([new BattleTestItem('item.ether', 88)])
    ->and(array_column($scene->describeList()->rows, 0))->toBe(['Ether', '+ Add an item', 'Use the default stock']);

  // Add the potion back, starting at one.
  $editor->moveVertical(1);
  $editor->confirm();
  expect($scene->describeList()->rows)->toBe([['Potion', []]]);
  $editor->confirm();
  $editor->moveVertical(-1);
  $editor->confirm();
  expect($editor->setup->inventory)->toEqual([new BattleTestItem('item.ether', 88), new BattleTestItem('item.potion', 2)]);

  // The items persist through member edits and the stage menus, and the preview party holds them.
  $editor->movePane(-1);
  $editor->confirm();
  $editor->moveVertical(1);
  $editor->moveHorizontal(1);
  expect($editor->setup->inventory)->toEqual([new BattleTestItem('item.ether', 88), new BattleTestItem('item.potion', 2)]);
  $editor->cancel();
  selectBattleTestPartySetting($editor, BattleTestPartySetting::ITEMS);
  $editor->confirm();
  $editor->moveVertical(10);
  expect($scene->describeList()->hints)->toContain(['confirm', 'Use']);
  $editor->confirm();
  expect($editor->setup->inventory)->toBeNull()->and($scene->describeList()->title)->toBe('Items (default stock)');
  $editor->cancel();
  expect($editor->focus)->toBe(ArenaSetupEditor::MANAGEMENT);
  $editor->cancel();
  expect($editor->focus)->toBe(ArenaSetupEditor::TROOPS);
});

it('sets a member\'s starting HP, MP and states in the member editor, near the top, with every control shown', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1)]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  $editor->movePane(1);
  $editor->confirm();
  expect(array_slice($editor->fields, 0, 5))->toBe(['actor', 'level', 'hp', 'mp', 'states'])
    ->and($scene->describeList()->note)->toBe('Surge needs HP below 10% or Presence.');

  // HP: left steps by one percent, a page step by ten; on other rows a page step still changes the level.
  $editor->moveVertical(2);
  $editor->moveHorizontal(-1);
  $editor->movePage(-1, 16);
  expect($editor->member->hp)->toEqual(new BattleTestVital(89))
    ->and($editor->member->level)->toBe(1)
    ->and($scene->describeList()->rows[2])->toBe(['HP', ['< 89% >']])
    ->and($scene->describeList()->hints)->toContain(['left', 'Change', 'right'],
      [MenuPager::PREVIOUS_ACTION, 'HP by 10', MenuPager::NEXT_ACTION], ['confirm', 'Choose'], [ToolNavigationAction::CANCEL->value, 'Done']);
  $editor->moveVertical(-1);
  $editor->movePage(1, 16);
  expect($editor->member->level)->toBe(11)
    ->and($scene->describeList()->hints)->toContain([MenuPager::PREVIOUS_ACTION, 'Lv by 10', MenuPager::NEXT_ACTION]);

  // Confirm on HP offers the crisis threshold from the actor's own ability; choosing it readies Surge.
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->focus)->toBe(ArenaSetupEditor::CHOOSER)
    ->and(array_column($scene->describeList()->rows, 0)[1])->toStartWith('Below Surge: ')
    ->and($scene->describeList()->hints)->toContain(['confirm', 'Set']);
  $threshold = $editor->getChoices()[1]['id'];
  $editor->moveVertical(-10);
  $editor->moveVertical(1);
  $editor->confirm();
  new ReflectionMethod(ArenaScene::class, 'refreshPreview')->invoke($scene);
  [$current, $total] = array_map(intval(...), explode('/', $scene->listPartyStats()[0][1]['HP']));
  expect($editor->member->hp)->toEqual(BattleTestVital::parse($threshold))
    ->and($current * 100)->toBeLessThan($total * 10)->and($current)->toBeGreaterThan(0)
    ->and($scene->describeList()->note)->toBe('Surge ready: Test finisher');

  // Counted in points instead, the amount stays and steps by points within the maximum.
  $editor->confirm();
  $editor->moveVertical(10);
  $editor->confirm();
  $editor->movePage(1, 16);
  $editor->moveHorizontal(1);
  expect($editor->member->hp?->unit)->toBe(BattleTestVitalUnit::POINTS);
  $maximum = $this->choices->getVitalMaximum($editor->setup, 0, 'hp');
  foreach (range(1, intdiv($maximum, 10) + 1) as $step) { $editor->movePage(1, 16); }
  expect($editor->member->hp?->amount)->toBe($maximum);

  // States toggle, none clears them, and Presence alone readies Surge at full HP.
  $editor->moveVertical(-10);
  $editor->moveVertical(2);
  $editor->confirm();
  $editor->moveVertical(-10);
  $editor->confirm();
  expect($editor->member->hp)->toBeNull();
  $editor->moveVertical(2);
  $editor->confirm();
  expect($editor->focus)->toBe(ArenaSetupEditor::CHOOSER)->and($editor->choosesSeveral)->toBeTrue()
    ->and($scene->describeList()->rows[0])->toBe(['[x] (None)', []]);
  $editor->moveVertical(1);
  $editor->confirm();
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->member->states)->toBe(['presence', 'poison'])
    ->and($scene->describeList()->rows[1])->toBe(['[x] Presence', []]);
  $editor->moveVertical(-2);
  $editor->confirm();
  expect($editor->member->states)->toBe([]);
  $editor->moveVertical(1);
  $editor->confirm();
  $editor->cancel();
  new ReflectionMethod(ArenaScene::class, 'refreshPreview')->invoke($scene);
  expect($scene->describeList()->rows[4])->toBe(['States', ['Presence']])
    ->and($scene->describeList()->note)->toBe('Surge ready: Test finisher')
    ->and($scene->listPartyStats()[0][1]['HP'])->toBe(sprintf('%1$d/%1$d', $maximum));
});

it('shows the same setup through the Terminal and the graphical theme, the note kept beneath the rows', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1, hp: new BattleTestVital(9))]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  $editor->movePane(1);
  $editor->confirm();
  $list = $scene->describeList();
  $terminal = implode("\n", $list->getTerminalRows(53));
  // A theme that hides optional hints still shows every control of the member editor.
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
  $canvas = BattleTestMenuPresentation::compose($theme, $list, true, $scene->listPartyStats(), 0, null, null, null, false);
  $text = implode(' ', array_map(static fn($layer): string => implode('', array_map(static fn($run): string => $run->text, $layer->runs)),
    $canvas->textLayers));

  expect($terminal)->toContain('< 9% >')->toContain('States')
    ->and($list->getTerminalNote(30))->toBe(['Surge ready: Test finisher'])
    ->and(new \Ichiloto\Engine\Scenes\Arena\BattleTestMenuList('Long', [], null, note: str_repeat('word ', 12))->getTerminalNote(20))
    ->toHaveCount(3)
    ->and($text)->toContain('< 9% >')->toContain('Surge ready: Test finisher')->toContain('9/100')
      ->toContain('Lv by 10')->toContain('Done');
});

it('starts a custom stock empty in one action and adds only the selected quantity', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1)]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  selectBattleTestPartySetting($editor, BattleTestPartySetting::ITEMS);
  $editor->confirm();

  $editor->confirm();
  expect($editor->setup->inventory)->toBe([])
    ->and(array_column($scene->describeList()->rows, 0))->toBe(['+ Add an item', 'Use the default stock']);
  $editor->confirm();
  $editor->confirm();
  $editor->moveVertical(-1);
  $editor->confirm();
  expect($editor->setup->inventory)->toEqual([new BattleTestItem('item.potion', 2)]);
});

it('derives a member\'s forms from the story facts its crisis ability\'s forms reference, and finds one by technique or value', function () {
  $forms = $this->choices->getFormChoices('shifter');

  expect(array_column($forms, 'name'))->toBe(['None', 'Plain Cut (Shifter form: 0)', 'Beast Rend (Shifter form: Beast)',
      'Twin Howl (Shifter form: Twin, Moon up: On)'])
    ->and(array_column($forms, 'technique'))->toBe([null, 'Plain Cut', 'Beast Rend', 'Twin Howl'])
    ->and($forms[3]['world']?->toArray())->toBe(['variables' => ['shifter_form' => 'twin'], 'switches' => ['moon_up' => true]])
    // A form chosen by a quest no test fight can set is not offered; actors whose techniques depend on no facts have no forms.
    ->and(array_column($forms, 'technique'))->not->toContain('Relic Strike')
    ->and($this->choices->getFormChoices('hero'))->toBe([])
    ->and($this->choices->findForm('shifter', 'beast rend')?->toArray())->toBe(['variables' => ['shifter_form' => 'beast']])
    ->and($this->choices->findForm('shifter', 'BEAST')?->toArray())->toBe(['variables' => ['shifter_form' => 'beast']])
    ->and($this->choices->findForm('shifter', '0')?->toArray())->toBe(['variables' => ['shifter_form' => 0]])
    ->and($this->choices->findForm('shifter', 'werewolf'))->toBeNull();
});

it('offers the technique of the chosen form in each fight, from a fresh world state that never reaches a save', function () {
  $parties = [];
  $settings = [];
  $base = new BattleTestSetup([new BattleTestMember('shifter', 1, hp: new BattleTestVital(9))]);
  foreach ($this->choices->getFormChoices('shifter') as $choice) {
    $setup = $base->withMember(0, $base->members[0]->withWorld($choice['world']))->withArena('arena.lake')->withBattleSize(2);
    $scene = battleConditionScene($this, $setup, $parties, $settings);
    new ReflectionMethod(ArenaScene::class, 'fight')->invoke($scene, 0);
    new ReflectionMethod(ArenaScene::class, 'fight')->invoke($scene, 0);
  }
  $offered = [];
  foreach ($parties as $index => $party) {
    $state = $settings[$index][\Ichiloto\Engine\Scenes\Battle\BattleConfig::TEST_WORLD_STATE] ?? null;
    $shifter = $party->members->toArray()[0];
    $offered[] = BattleCommandCatalog::getAvailableCrisisOption($shifter, $party, $state)?->label;
  }

  expect($offered)->toBe([null, null, 'Plain Cut', 'Plain Cut', 'Beast Rend', 'Beast Rend', 'Twin Howl', 'Twin Howl'])
    ->and($settings[0])->toBe(['battleArena' => 'arena.lake'])
    // Each fight gets its own state; the party, and so a save, holds none of it.
    ->and($settings[2][\Ichiloto\Engine\Scenes\Battle\BattleConfig::TEST_WORLD_STATE])
    ->not->toBe($settings[3][\Ichiloto\Engine\Scenes\Battle\BattleConfig::TEST_WORLD_STATE])
    ->and(json_encode($parties[4]->members->toArray()[0]->toArray()))->not->toContain('shifter_form');
});

it('sets the form in the member editor and the note reads the chosen form\'s readiness', function () {
  $setup = new BattleTestSetup([new BattleTestMember('shifter', 1, states: ['presence']), new BattleTestMember('hero', 1)]);
  $parties = [];
  $scene = battleConditionScene($this, $setup, $parties);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  $editor->movePane(1);
  $editor->confirm();

  expect(array_slice($editor->fields, 0, 6))->toBe(['actor', 'level', 'hp', 'mp', 'states', 'form'])
    ->and($scene->describeList()->note)->toBe('Surge is active, but none of its forms applies; choose its Form.')
    ->and($scene->describeList()->rows[5])->toBe(['Form', ['< None >']]);
  $editor->moveVertical(5);
  $editor->moveHorizontal(1);
  $editor->moveHorizontal(1);
  expect($editor->member->world?->toArray())->toBe(['variables' => ['shifter_form' => 'beast']])
    ->and($scene->describeList()->rows[5])->toBe(['Form', ['< Beast Rend >']])
    ->and($scene->describeList()->note)->toBe('Surge ready: Beast Rend')
    ->and($scene->describeList()->hints)->toContain(['left', 'Change', 'right'], ['confirm', 'Choose']);
  $editor->confirm();
  expect($editor->focus)->toBe(ArenaSetupEditor::CHOOSER)->and($editor->choiceIndex)->toBe(2);
  $editor->moveVertical(1);
  $editor->confirm();
  expect($scene->describeList()->note)->toBe('Surge ready: Twin Howl');

  // The form belongs to the actor: another actor has none, and members without forms show no row.
  $editor->cancel();
  selectBattleTestPartySetting($editor, BattleTestPartySetting::MEMBERS);
  $editor->confirm();
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->fields)->not->toContain('form');
  expect(BattleTestMember::fromArray($editor->setup->members[0]->toArray()))->toEqual($editor->setup->members[0])
    ->and($editor->setup->members[0]->toArray()['world'])->toBe(['variables' => ['shifter_form' => 'twin'], 'switches' => ['moon_up' => true]])
    ->and($editor->setup->members[0]->withActor('hero')->world)->toBeNull()
    ->and(BattleTestSetup::fromArray($editor->setup->toArray()))->toEqual($editor->setup);
});

it('names a form the member does not have and two members whose forms disagree', function () {
  $beast = new \Ichiloto\Engine\Scenes\Arena\BattleTestWorld(['shifter_form' => 'beast']);
  $setup = new BattleTestSetup([
    new BattleTestMember('shifter', 1, world: new \Ichiloto\Engine\Scenes\Arena\BattleTestWorld(['shifter_form' => 'werewolf'])),
    new BattleTestMember('hero', 1, world: $beast),
  ]);
  $conflict = new BattleTestSetup([new BattleTestMember('shifter', 1, world: $beast),
    new BattleTestMember('shifter', 1, world: new \Ichiloto\Engine\Scenes\Arena\BattleTestWorld(['shifter_form' => 0]))]);

  expect($setup->getProblems($this->actors, $this->items))->toBe([
    'Member 1 (shifter): Shifter form: Werewolf is not one of its forms (Shifter form: 0; Shifter form: Beast; Shifter form: Twin, Moon up: On).',
    'Member 2 (hero): Shifter form: Beast is not one of its forms (it has none).',
    'Members choose forms that set one story fact differently, so a fight cannot have both.',
  ])->and($conflict->getProblems($this->actors, $this->items))
    ->toBe(['Members choose forms that set one story fact differently, so a fight cannot have both.'])
    ->and($conflict->createWorldState())->toBeNull()
    ->and(fn() => BattleTestMember::fromArray(['actor' => 'shifter', 'level' => 1, 'world' => ['variables' => []]]))
    ->toThrow(InvalidArgumentException::class, 'at least one story fact')
    ->and(fn() => BattleTestMember::fromArray(['actor' => 'shifter', 'level' => 1, 'world' => ['flags' => []]]))
    ->toThrow(InvalidArgumentException::class, 'has no flags');
});


it('draws Engine tool chrome without evaluating a project menu theme and keeps Terminal tool colours', function (bool $projectTheme) {
  ConfigStore::put(\Ichiloto\Engine\Util\Config\PlaySettings::class,
    new \Ichiloto\Engine\Util\Config\PlaySettings(['width' => 135, 'height' => 36]));
  $parties = [];
  $scene = battleConditionScene($this, new BattleTestSetup([new BattleTestMember('hero', 1)]), $parties);
  $assets = $this->root . '/assets';
  if ($projectTheme) {
    mkdir($assets . '/Data/Presentation');
    file_put_contents($assets . '/Data/Presentation/menus.php', "<?php throw new RuntimeException('A tool must never read the game theme.');");
  }
  require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
  $transport = new \Tests\Support\Input\FakeRendererTransport();
  $transport->batches[] = [\Ichiloto\Engine\Rendering\Transport\RendererEvent::fromJson(
    '{"protocol":2,"type":"ready","capabilities":["graphical_canvas","canvas_clip_opacity"]}')];
  $runtime = new \Ichiloto\Engine\Rendering\Runtime\RendererRuntime(
    new \Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig(
      new \Ichiloto\Engine\Rendering\Transport\RendererProcessConfig(['not-launched']), $assets), $transport);
  $scene->getGame()->useRendererRuntime($runtime);
  try {
    $runtime->start('Synthetic tool', 80, 30);
    $canvas = $scene->getPresentationCanvas();
    expect(new ReflectionProperty(\Ichiloto\Engine\Scenes\Arena\ArenaScene::class, 'presentationError')->getValue($scene))->toBeNull();
    expect($canvas)->toBeInstanceOf(\Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas::class);
    $theme = \Ichiloto\Engine\UI\Presentation\ToolTheme::load();
    $background = array_find($canvas->textLayers, fn($layer) => $layer->id === 'menu-background');
    expect($background?->runs[0]->background)->toEqual($theme->getColor(\Ichiloto\Engine\UI\Presentation\ToolColor::BACKGROUND))
      ->and($canvas->images)->toBe([]);
    new ReflectionMethod($scene, 'buildPanels')->invoke($scene);
    $panel = new ReflectionProperty($scene, 'listPanel')->getValue($scene);
    expect($panel->getForegroundColor())->toBe($theme->getTerminalColor(\Ichiloto\Engine\UI\Presentation\ToolColor::TEXT))
      ->and($panel->getBackgroundColor())->toBe($theme->getTerminalColor(\Ichiloto\Engine\UI\Presentation\ToolColor::BACKGROUND));
  } finally { $runtime->shutdown(); }
})->with([false, true]);


it('configures normal, preemptive and ambush openings in the shared party setup and preserves their round trips and runtime ownership', function () {
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1)], inventory: [new BattleTestItem('item.potion', 3)]);
  $parties = $settings = [];
  $scene = battleConditionScene($this, $setup, $parties, $settings);
  $editor = new ReflectionProperty(ArenaScene::class, 'editor')->getValue($scene);
  selectBattleTestPartySetting($editor, BattleTestPartySetting::OPENING);
  $fight = new ReflectionMethod(ArenaScene::class, 'fight');
  foreach ([null, ...\Ichiloto\Engine\Battle\EncounterAdvantage::cases()] as $opening) {
    expect($editor->setup->firstStrike)->toBe($opening);
    $serialized = (new ProjectBattleTest('Rat', $editor->setup))->toArray();
    expect(ProjectBattleTest::fromArray($serialized)->setup)->toEqual($editor->setup);
    $fight->invoke($scene, 0);
    $recorded = $settings[array_key_last($settings)];
    expect($recorded['firstStrike'] ?? null)->toBe($opening?->value)
      ->and(array_key_exists('firstStrike', $recorded))->toBe($opening !== null);
    $copy = $editor->setup->withMember(0, $editor->setup->members[0]->withLevel(2))->withArena('other')->withInventory([])->withBattleSize(1);
    expect($copy->firstStrike)->toBe($opening)->and($editor->setup->inventory)->toEqual($setup->inventory);
    $editor->moveHorizontal(1);
  }
  expect($editor->setup->firstStrike)->toBeNull()->and($setup->firstStrike)->toBeNull()
    ->and(ProjectBattleTest::fromArray(['firstStrike' => 'party'])->createSetup($this->actors)->firstStrike)
    ->toBe(\Ichiloto\Engine\Battle\EncounterAdvantage::PARTY);
  expect(fn() => BattleTestSetup::fromArray(['members' => [['actor' => 'hero', 'level' => 1]], 'firstStrike' => 'unknown']))
    ->toThrow(InvalidArgumentException::class, 'firstStrike');
});
