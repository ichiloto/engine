<?php

use Ichiloto\Engine\Scenes\Arena\ArenaSetupEditor;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;

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
