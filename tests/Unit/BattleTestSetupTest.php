<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Scenes\Arena\BattleTestLoadoutCatalog;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;

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

function writeBattleLoadoutSources(string $root): void
{
  writeSkillRecords(
    $root,
    new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test Strike', '', '', 2, 0),
    new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test Call', '', '', 4, 0, effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect('12', variance: 0)]),
    new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test Open Call', '', '', 4, 0),
    new \Ichiloto\Engine\Entities\Skills\MagicSkill('Test Flame', '', '', 3, 0),
    new \Ichiloto\Engine\Entities\Skills\MagicSkill('Test Travel', '', '', 0, 0, occasion: \Ichiloto\Engine\Entities\Enumerations\Occasion::MENU_SCREEN),
  );
  foreach (['test-call', 'open-call', 'broken-call'] as $id) {
    $directory = $root . '/assets/Cutscenes/Summons/' . $id;
    mkdir($directory, 0777, true);
    $data = ['id' => $id, 'name' => ucfirst($id), 'linkedActionId' => match ($id) {
      'broken-call' => 'Missing action', 'open-call' => 'Test Open Call', default => 'Test Call',
    },
      'availability' => ['conditions' => [['type' => 'event', 'name' => 'unearned_story_unlock']]]];
    if ($id === 'test-call') {
      $data['wielders'] = ['mode' => 'characters', 'characters' => ['Hero'], 'tenancy' => 'exclusive'];
    }
    file_put_contents($directory . '/' . $id . '.data.php', '<?php return ' . var_export($data, true) . ';');
    file_put_contents($directory . '/' . $id . '.timeline.php', "<?php return ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [], 'cues' => []];");
  }
}

it('grants isolated battle commands, abilities and spells without modifying actor definitions', function () {
  writeBattleLoadoutSources($this->root);
  $member = new BattleTestMember('hero', 1, commands: [BattleCommandType::SKILL, BattleCommandType::MAGIC],
    skills: ['Test Strike', 'Test Flame']);
  $setup = new BattleTestSetup([$member]);
  $party = $setup->createParty($this->actors, $this->items);
  $hero = $party->leader;
  expect(array_column(BattleCommandCatalog::buildCommands($hero, $party), 'name'))->toBe(['Skill', 'Magic'])
    ->and(array_column(BattleCommandCatalog::buildOptions($hero, $party, 'Skill'), 'label'))->toBe(['Test Strike (2 MP)'])
    ->and(array_column(BattleCommandCatalog::buildOptions($hero, $party, 'Magic'), 'label'))->toBe(['Test Flame (3 MP)'])
    ->and(BattleCommandCatalog::buildOptions($hero, $party, 'Attack'))->toBe([])
    ->and($this->actors->require('hero', 'test')->createCharacter()->battleCommandLoadout)->toBeNull()
    ->and($this->actors->require('hero', 'test')->createCharacter()->spellbook->getLearnedSpells())->toBe([])
    ->and($setup->createParty($this->actors, $this->items)->leader)->not->toBe($hero)
    ->and(BattleTestSetup::getFromParty($party)->members[0]->skills)->toBe($member->skills);
});

it('makes an explicitly selected gated summon usable at menu and resolution with normal cost and effect', function () {
  writeBattleLoadoutSources($this->root);
  // One action per summon, as authored projects require.
  unlink($this->root . '/assets/Cutscenes/Summons/open-call/open-call.data.php');
  $party = new BattleTestSetup([new BattleTestMember('hero', 1, summons: ['test-call'])])->createParty($this->actors, $this->items);
  $hero = $party->leader;
  $option = BattleCommandCatalog::buildOptions($hero, $party, 'Summon')[0];
  expect($hero->hasSummon('test-call'))->toBeTrue()
    ->and($option->type)->toBe(BattleCommandType::SUMMON)
    ->and(BattleCommandCatalog::canUseSummonAction($hero, $party, 'Test Call'))->toBeTrue()
    ->and(new SummonCutsceneLibrary()->loadCompiledOrCompileByLinkedActionId('Test Call'))->not->toBeNull();
  $target = battleTestActor('target')->createCharacter();
  $before = $hero->stats->currentMp;
  $option->action->execute($hero, [$target]);
  expect($hero->stats->currentMp)->toBe($before - 4)
    ->and($option->action->lastResult)->not->toBeNull()
    ->and($target->stats->currentHp)->toBeLessThan(40);
  $hero->unassignSummon('test-call');
  expect(BattleCommandCatalog::canUseSummonAction($hero, $party, 'Test Call'))->toBeFalse()
    ->and(BattleCommandCatalog::buildOptions($hero, $party, 'Summon'))->toBe([]);
});

it('does not leak sandbox story access into ordinary parties or restored characters', function () {
  writeBattleLoadoutSources($this->root);
  unlink($this->root . '/assets/Cutscenes/Summons/open-call/open-call.data.php');
  $party = new BattleTestSetup([new BattleTestMember('hero', 1, summons: ['test-call'])])->createParty($this->actors, $this->items);
  $restored = unserialize(serialize($party->leader));
  $ordinary = new \Ichiloto\Engine\Entities\Party();
  $ordinary->addMember($restored);
  expect($party->leader->toArray())->not->toHaveKey('battleCommandLoadout')
    ->and($restored->battleCommandLoadout)->toBeNull()
    ->and(BattleCommandCatalog::canUseSummonAction($restored, $ordinary, 'Test Call'))->toBeFalse()
    ->and(BattleCommandCatalog::buildOptions($restored, $ordinary, 'Summon'))->toBe([])
    ->and(new BattleTestSetup([new BattleTestMember('hero', 1)])->createParty($this->actors, $this->items)->leader->summons)->toBe([]);
  $party->leader->__unserialize($party->leader->__serialize());
  expect($party->leader->battleCommandLoadout)->toBeNull();
});

it('refuses unknown, field-only or wrongly routed skills and ineligible, unlinked or exclusive summons', function () {
  writeBattleLoadoutSources($this->root);
  $setup = new BattleTestSetup([
    new BattleTestMember('hero', 1, skills: ['Missing', 'Test Travel', 'Test Call'], summons: ['missing', 'broken-call', 'test-call']),
    new BattleTestMember('veteran', 1, summons: ['test-call']),
  ]);
  $problems = $setup->getProblems($this->actors, $this->items);
  expect(implode('\n', $problems))->toContain('no skill Missing', 'Test Travel cannot be used in battle', 'select its summon instead',
    'no summon missing', 'no valid linked action', 'not eligible', 'choose one holder')
    ->and(fn() => $setup->createParty($this->actors, $this->items))->toThrow(InvalidArgumentException::class);
});

it('preserves loadouts on level and equipment changes and clears them when the actor changes', function () {
  $member = new BattleTestMember('hero', 1, commands: [BattleCommandType::SUMMON], skills: ['Test Strike'], summons: ['test-call']);
  $changed = $member->withLevel(4)->withEquipment('Weapon', 'equipment.sword');
  expect([$changed->commands, $changed->skills, $changed->summons])->toBe([$member->commands, $member->skills, $member->summons])
    ->and([$changed->withActor('veteran')->commands, $changed->withActor('veteran')->skills, $changed->withActor('veteran')->summons])->toBe([null, [], []])
    ->and(fn() => new BattleTestMember('hero', 1, commands: []))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleTestMember('hero', 1, skills: ['A', 'A']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleTestMember('hero', 1, summons: ['']))->toThrow(InvalidArgumentException::class);
});

it('offers real battle resources independently of story gates and excludes invalid choices', function () {
  writeBattleLoadoutSources($this->root);
  $catalog = BattleTestLoadoutCatalog::getProjectCatalog();
  expect(array_column($catalog->getSkillChoices(false), 'id'))->toBe(['Test Strike'])
    ->and(array_column($catalog->getSkillChoices(true), 'id'))->toBe(['Test Flame'])
    ->and(array_column($catalog->getSummonChoices($this->actors->require('hero', 'test')->createCharacter()), 'id'))->toBe(['open-call', 'test-call'])
    ->and(array_column($catalog->getSummonChoices($this->actors->require('veteran', 'test')->createCharacter()), 'id'))->toBe(['open-call']);
});

it('retains MP affordability and command restrictions for explicitly available summons', function () {
  writeBattleLoadoutSources($this->root);
  $party = new BattleTestSetup([new BattleTestMember('hero', 1, summons: ['test-call'])])->createParty($this->actors, $this->items);
  $hero = $party->leader;
  $option = BattleCommandCatalog::buildOptions($hero, $party, 'Summon')[0];
  $target = battleTestActor('target')->createCharacter();
  $hero->stats->currentMp = 3;
  $option->action->execute($hero, [$target]);
  expect($hero->stats->currentMp)->toBe(3)->and($target->stats->currentHp)->toBe(40);
  $hero->battleCommandLoadout = new \Ichiloto\Engine\Battle\BattleCommandLoadout([BattleCommandType::ATTACK], ['test-call']);
  expect(BattleCommandCatalog::buildOptions($hero, $party, 'Summon'))->toBe([])
    ->and(BattleCommandCatalog::canUseSummonAction($hero, $party, 'Test Call'))->toBeFalse();
});

it('refuses ambiguous summon action identities instead of granting the wrong cutscene', function () {
  writeBattleLoadoutSources($this->root);
  $file = $this->root . '/assets/Cutscenes/Summons/open-call/open-call.data.php';
  $data = require $file;
  $data['linkedActionId'] = 'Test Call';
  file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
  $setup = new BattleTestSetup([new BattleTestMember('hero', 1, summons: ['test-call'])]);
  expect(implode('\n', $setup->getProblems($this->actors, $this->items)))->toContain('action must be unambiguous');
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

it('equips what fits a slot', function () {
  $member = new BattleTestMember('hero', 1, ['Weapon' => 'equipment.sword', 'Body' => null]);
  $hero = new BattleTestSetup([$member])->createParty($this->actors, $this->items)->members->toArray()[0];
  $equipped = array_column(array_map(static fn($slot): array => ['slot' => $slot->name, 'item' => $slot->equipment?->id], $hero->equipment), 'item', 'slot');

  expect($equipped['Weapon'])->toBe('equipment.sword')
    ->and($equipped['Body'])->toBeNull();
});

it('names every problem with a setup and refuses to build it, rather than leaving parts out', function () {
  $setup = new BattleTestSetup([
    new BattleTestMember('hero', 1, ['Body' => 'equipment.sword', 'Head' => 'item.potion', 'Cape' => null, 'Weapon' => 'equipment.missing']),
    new BattleTestMember('ghost', 1),
    new BattleTestMember('veteran', 500),
  ]);

  expect($setup->getProblems($this->actors, $this->items))->toBe([
    'Member 1 (hero): Sword does not go in the Body slot.',
    'Member 1 (hero): Potion is not equipment.',
    'Member 1 (hero): has no Cape slot (its slots: Weapon, Shield, Head, Body, Accessory).',
    'Member 1 (hero): the project has no item equipment.missing.',
    'Member 2 (ghost): the project has no such actor.',
    'Member 3 (veteran): level 500 is beyond its highest, 100.',
  ])->and(fn() => $setup->createParty($this->actors, $this->items))
    ->toThrow(InvalidArgumentException::class, 'Member 2 (ghost): the project has no such actor.')
    ->and(new BattleTestSetup([new BattleTestMember('hero', 3)])->getProblems($this->actors, $this->items))->toBe([]);
});

it('sets up the starting party, each actor at its authored level and equipment', function () {
  file_put_contents($this->root . '/assets/Data/system.php', "<?php return ['title' => 'Test', 'currency' => [], 'startingPositions' => ['player' => []],
    'startingParty' => ['veteran', 'hero']];");
  $setup = BattleTestSetup::getFromStartingParty($this->actors);

  expect(array_map(static fn(BattleTestMember $member): array => [$member->actorId, $member->level], $setup->members))
    ->toBe([['veteran', $this->actors->get('veteran')->createCharacter()->level], ['hero', 1]])
    ->and($setup->members[1]->equipment)->toBe(['Weapon' => null, 'Shield' => null, 'Head' => null, 'Body' => null, 'Accessory' => null]);
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
