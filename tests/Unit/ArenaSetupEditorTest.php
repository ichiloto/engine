<?php

use Ichiloto\Engine\Scenes\Arena\ArenaSetupEditor;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Battle\BattleCommandType;

function arenaSetupEditor(array $members = [['hero', 5]], int $troops = 3): ArenaSetupEditor
{
  return new ArenaSetupEditor(
    new BattleTestSetup(array_map(static fn(array $member): BattleTestMember => new BattleTestMember(...$member), $members)),
    $troops,
    ['hero', 'mage', 'thief'],
    static fn(string $actorId): array => ['Weapon', 'Body'],
    static fn(string $actorId, string $slot): array => $slot === 'Weapon'
      ? [['id' => null, 'name' => '(None)'], ['id' => 'equipment.sword', 'name' => 'Sword'], ['id' => 'equipment.axe', 'name' => 'Axe']]
      : [['id' => null, 'name' => '(None)']],
    static fn(string $actorId): int => $actorId === 'mage' ? 50 : 99,
  );
}

it('moves from the troop list down into the party and back up, and fights or leaves from the list', function () {
  $editor = arenaSetupEditor();

  $editor->moveVertical(1);
  $editor->moveVertical(1);
  expect($editor->troopIndex)->toBe(2)->and($editor->focus)->toBe(ArenaSetupEditor::TROOPS)
    ->and($editor->confirm())->toBe(ArenaSetupEditor::FIGHT);

  $editor->moveVertical(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::PARTY)->and($editor->memberIndex)->toBe(0);
  $editor->moveVertical(-1);
  expect($editor->focus)->toBe(ArenaSetupEditor::TROOPS)->and($editor->troopIndex)->toBe(2)
    ->and($editor->cancel())->toBe(ArenaSetupEditor::QUIT);
});

it('changes a member\'s actor and level within the actor\'s limits', function () {
  $editor = arenaSetupEditor();
  $editor->moveVertical(5);
  $editor->confirm();

  expect($editor->focus)->toBe(ArenaSetupEditor::MEMBER)->and($editor->fields)->toBe(['actor', 'level', 'slot:Weapon', 'slot:Body']);
  $editor->moveVertical(1);
  $editor->moveHorizontal(1);
  $editor->stepLevel(10);
  $editor->stepLevel(1000);
  expect($editor->member->level)->toBe(99);
  $editor->stepLevel(-1000);
  expect($editor->member->level)->toBe(1);

  $editor->stepLevel(80);
  $editor->moveVertical(-1);
  $editor->moveHorizontal(1);
  // The mage tops out at 50, so the level follows the actor.
  expect($editor->member->actorId)->toBe('mage')->and($editor->member->level)->toBe(50);
  $editor->moveHorizontal(-2);
  expect($editor->member->actorId)->toBe('thief');
});

it('chooses equipment from what the slot can hold, starting on what is worn', function () {
  $editor = arenaSetupEditor();
  $editor->moveVertical(3);
  $editor->confirm();
  $editor->moveVertical(2);
  $editor->confirm();

  expect($editor->focus)->toBe(ArenaSetupEditor::CHOOSER)->and(array_column($editor->getChoices(), 'name'))->toBe(['(None)', 'Sword', 'Axe'])
    ->and($editor->choiceIndex)->toBe(0);
  $editor->moveVertical(2);
  $editor->confirm();
  expect($editor->focus)->toBe(ArenaSetupEditor::MEMBER)->and($editor->member->equipment['Weapon'])->toBe('equipment.axe');

  $editor->confirm();
  expect($editor->choiceIndex)->toBe(2);
  $editor->cancel();
  $editor->cancel();
  expect($editor->focus)->toBe(ArenaSetupEditor::PARTY)->and($editor->member->equipment['Weapon'])->toBe('equipment.axe');
});

it('adds a member in an empty place and removes one, keeping the last', function () {
  $editor = arenaSetupEditor();
  $editor->moveVertical(3);
  $editor->moveVertical(2);
  expect($editor->member)->toBeNull();
  $editor->confirm();
  expect($editor->setup->members)->toHaveCount(2)
    ->and($editor->member->actorId)->toBe('mage')->and($editor->member->level)->toBe(1)
    ->and($editor->fields)->toContain('remove');

  $editor->moveVertical(10);
  $editor->confirm();
  expect($editor->setup->members)->toHaveCount(1)->and($editor->focus)->toBe(ArenaSetupEditor::PARTY);
  $editor->moveVertical(-5);
  $editor->moveVertical(3);
  $editor->confirm();
  expect($editor->fields)->not->toContain('remove');
});

it('toggles command and resource selections through constrained pickers without changing other setup fields', function () {
  $editor = new ArenaSetupEditor(new BattleTestSetup([new BattleTestMember('hero', 5)]), 1, ['hero'],
    static fn() => [], static fn() => [], static fn() => 99,
    static fn($member, $field) => match ($field) {
      'commands' => [['id' => null, 'name' => 'Normal'], ['id' => 'magic', 'name' => 'Magic']],
      'skills' => [['id' => null, 'name' => 'Clear'], ['id' => 'Test Strike', 'name' => 'Test Strike']],
      'magic' => [['id' => null, 'name' => 'Clear'], ['id' => 'Test Flame', 'name' => 'Test Flame']],
      'summons' => [['id' => null, 'name' => 'Clear'], ['id' => 'test-call', 'name' => 'Test Call']],
    });
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->fields)->toBe(['actor', 'level', 'commands', 'skills', 'magic', 'summons']);
  $editor->moveVertical(2);
  $editor->confirm();
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->member->commands)->not->toContain(BattleCommandType::MAGIC)
    ->and($editor->isChoiceSelected('magic'))->toBeFalse()
    ->and($editor->focus)->toBe(ArenaSetupEditor::CHOOSER);
  $editor->confirm();
  expect($editor->member->commands)->toContain(BattleCommandType::MAGIC);
  $editor->moveVertical(-1);
  $editor->confirm();
  expect($editor->member->commands)->toBeNull();
  foreach (['skills' => 'Test Strike', 'magic' => 'Test Flame', 'summons' => 'test-call'] as $field => $id) {
    $editor->cancel();
    $editor->moveVertical(1);
    $editor->confirm();
    $editor->moveVertical(1);
    $editor->confirm();
    expect($editor->isChoiceSelected($id))->toBeTrue();
  }
  expect($editor->member->skills)->toBe(['Test Strike', 'Test Flame'])
    ->and($editor->member->summons)->toBe(['test-call'])->and($editor->member->level)->toBe(5);
  $editor->moveVertical(-1);
  $editor->confirm();
  expect($editor->member->summons)->toBe([])->and($editor->member->skills)->toBe(['Test Strike', 'Test Flame']);
});

it('chooses the arena with left and right on the troop list, the default first, where arenas are offered', function () {
  $offered = new ArenaSetupEditor(new BattleTestSetup([new BattleTestMember('hero', 5)]), 3, ['hero'],
    static fn(): array => [], static fn(): array => [], static fn(): int => 99, null, ['arena.road', 'arena.lake']);

  expect($offered->offersArenas)->toBeTrue()->and($offered->setup->arena)->toBeNull();
  $offered->moveHorizontal(1);
  expect($offered->setup->arena)->toBe('arena.road');
  $offered->moveHorizontal(1);
  expect($offered->setup->arena)->toBe('arena.lake');
  $offered->moveHorizontal(1);
  expect($offered->setup->arena)->toBeNull();
  $offered->moveHorizontal(-1);
  expect($offered->setup->arena)->toBe('arena.lake');

  // Editing the party keeps the arena chosen.
  $offered->moveVertical(5);
  $offered->confirm();
  $offered->moveVertical(1);
  $offered->moveHorizontal(1);
  expect($offered->setup->members[0]->level)->toBe(6)->and($offered->setup->arena)->toBe('arena.lake');

  // Where nothing draws an arena, left and right on the list change nothing.
  $plain = arenaSetupEditor();
  $plain->moveHorizontal(1);
  expect($plain->offersArenas)->toBeFalse()->and($plain->setup->arena)->toBeNull();
});

it('switches to Party from a scrolled troop row through the shared page actions', function () {
  $editor = arenaSetupEditor(troops: 26);
  $editor->selectTroop(17);
  $scene = new class extends \Ichiloto\Engine\Scenes\Arena\ArenaScene {
    public function __construct() {}
    public function render(): void {}
  };
  new ReflectionProperty($scene, 'camera')->setValue($scene, $this->createStub(\Ichiloto\Engine\Rendering\Camera::class));
  new ReflectionProperty($scene, 'editor')->setValue($scene, $editor);
  new ReflectionProperty($scene, 'troops')->setValue($scene, array_map(
    static fn(int $i) => new \Ichiloto\Engine\Entities\Troop('Troop ' . $i, []), range(1, 26)));

  $input = new ReflectionClass(\Ichiloto\Engine\IO\InputManager::class);
  $bindings = $input->getProperty('config');
  $key = $input->getProperty('keyPress');
  $priorKey = $input->getProperty('previousKeyPress');
  $restore = [$bindings->getValue(), $key->getValue(), $priorKey->getValue()];
  try {
    \Ichiloto\Engine\IO\InputManager::setBindings([]);
    $priorKey->setValue(null, null);
    $list = $scene->describeList();
    expect($list->selected)->toBe(17)
      ->and($list->hints)->toContain(['menu_page_next', 'Party']);

    $key->setValue(null, \Ichiloto\Engine\IO\Enumerations\KeyCode::PAGE_DOWN);
    $scene->update();
    $list = $scene->describeList();
    expect($editor->focus)->toBe(ArenaSetupEditor::PARTY)
      ->and($editor->memberIndex)->toBe(0)
      ->and($list->selected)->toBeNull()
      ->and($list->anchor)->toBe(17)
      ->and($list->hints)->toContain(['menu_page_previous', 'Troops']);

    $key->setValue(null, \Ichiloto\Engine\IO\Enumerations\KeyCode::PAGE_UP);
    $scene->update();
    expect($editor->focus)->toBe(ArenaSetupEditor::TROOPS)
      ->and($scene->describeList()->selected)->toBe(17);
  } finally {
    foreach ([$bindings, $key, $priorKey] as $index => $property) {
      $property->setValue(null, $restore[$index]);
    }
  }
});

it('keeps the composed troop and its row when paging to Party and back', function () {
  $composer = new \Ichiloto\Engine\Scenes\Arena\TestTroopComposer(['Rat'], static fn() => [3, 4]);
  $editor = new ArenaSetupEditor(new BattleTestSetup([new BattleTestMember('hero', 5)]), 26, ['hero'],
    static fn() => [], static fn() => [], static fn() => 99, composer: $composer);
  $editor->selectTroop(26);
  $editor->confirm();
  $editor->confirm();
  $editor->movePage(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::COMPOSER)
    ->and($composer->focus)->toBe($composer::ENEMIES);
  $editor->confirm();
  $editor->moveVertical(1);
  $row = $composer->rowIndex;
  $troop = $composer->troop;
  $editor->movePage(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::PARTY)
    ->and($editor->listFocus)->toBe(ArenaSetupEditor::COMPOSER);
  $editor->confirm();
  $editor->movePage(1);
  expect($editor->member->level)->toBe(15)
    ->and($editor->focus)->toBe(ArenaSetupEditor::MEMBER);
  $editor->cancel();
  $editor->movePage(-1);
  expect($editor->focus)->toBe(ArenaSetupEditor::COMPOSER)
    ->and($composer->rowIndex)->toBe($row)
    ->and($composer->troop)->toBe($troop);
});

it('offers starting conditions and the party\'s items only where they can be set, through the same small key set', function () {
  $plain = arenaSetupEditor();
  $plain->movePage(1);
  $plain->movePage(1);
  expect($plain->focus)->toBe(ArenaSetupEditor::PARTY)->and($plain->offersCondition)->toBeFalse();

  $stock = [new \Ichiloto\Engine\Scenes\Arena\BattleTestItem('item.potion', 99)];
  $editor = new ArenaSetupEditor(new BattleTestSetup([new BattleTestMember('hero', 5)], inventory: []), 1, ['hero'],
    static fn() => ['Weapon'], static fn() => [['id' => null, 'name' => '(None)']], static fn() => 99,
    conditionChoicesFor: static fn($member, string $field): array => $field === 'states'
      ? [['id' => null, 'name' => '(None)'], ['id' => 'focus', 'name' => 'Focus']]
      : [['id' => null, 'name' => 'Full'], ['id' => '0%', 'name' => 'Knocked out'], ['id' => '40', 'name' => 'In points: 40 of 40']],
    vitalMaximumFor: static fn() => 40,
    inventoryEditor: new \Ichiloto\Engine\Scenes\Arena\BattleTestInventoryEditor(
      static fn() => [['id' => 'item.potion', 'name' => 'Potion', 'maxQuantity' => 99]], static fn() => $stock));

  // The setup's own items, an empty list here, are what the items editor starts from.
  expect($editor->inventoryEditor->items)->toBe([])->and($editor->inventoryEditor->rows)->toBe(['add', 'default']);
  $editor->movePage(1);
  $editor->confirm();
  expect($editor->fields)->toBe(['actor', 'level', 'hp', 'mp', 'states', 'slot:Weapon']);
  $editor->moveVertical(3);
  $editor->confirm();
  $editor->moveVertical(2);
  $editor->confirm();
  expect($editor->member->mp)->toEqual(new \Ichiloto\Engine\Scenes\Arena\BattleTestVital(40, \Ichiloto\Engine\Scenes\Arena\BattleTestVitalUnit::POINTS));
  $editor->moveHorizontal(1);
  $editor->movePage(-1);
  $editor->moveHorizontal(-1);
  expect($editor->member->mp?->amount)->toBe(29);
  $editor->cancel();
  $editor->movePage(1);
  expect($editor->focus)->toBe(ArenaSetupEditor::ITEMS);
  $editor->confirm();
  $editor->confirm();
  $editor->moveVertical(1);
  $editor->confirm();
  expect($editor->setup->inventory)->toEqual([new \Ichiloto\Engine\Scenes\Arena\BattleTestItem('item.potion', 98)])
    ->and($editor->cancel())->toBeNull()->and($editor->focus)->toBe(ArenaSetupEditor::PARTY);
});
