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
