<?php

use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Scenes\Arena\ArenaSetupEditor;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Scenes\Arena\BattleTestTroop;
use Ichiloto\Engine\Scenes\Arena\TestTroopComposer;

/** A composer over two synthetic enemies; new members stand a column further on, and the battle arranges them in a row. */
function testTroopComposer(bool $graphical = true, bool $keeps = true, ?BattleTestTroop $troop = null): TestTroopComposer
{
  return new TestTroopComposer(['Bat', 'Wolf'],
    static fn(BattleTestTroop $troop, string $enemy): array => [10 + 20 * count($troop->members), 5],
    $graphical ? static fn(BattleTestTroop $troop): array => array_map(static fn(?BattleStageSlot $place, int $index): BattleStageSlot =>
      $place ?? new BattleStageSlot(100 + 200 * $index, 40, 120, 90), $troop->getPlaces(), array_keys($troop->getPlaces())) : null,
    $keeps, $troop);
}

it('adds enemies where the terminal field stands new members, then offers fighting and keeping', function () {
  $composer = testTroopComposer();
  expect($composer->rows)->toBe(['add']);

  $composer->confirm();
  expect($composer->focus)->toBe(TestTroopComposer::ENEMIES);
  $composer->moveVertical(1);
  $composer->confirm();
  $composer->moveVertical(1);
  $composer->confirm();
  $composer->confirm();

  expect($composer->troop->getEnemies())->toBe(['Wolf', 'Wolf'])
    ->and(array_column($composer->troop->members, 'position'))->toBe([[10, 5], [30, 5]])
    ->and($composer->rows)->toBe(['member:0', 'member:1', 'add', 'fight', 'keep'])
    ->and($composer->memberIndex)->toBe(1)
    ->and($composer->troop->toTroopData('Test Troop'))->toBe(['name' => 'Test Troop', 'enemies' => [
      ['enemy' => 'Wolf', 'position' => [10, 5]], ['enemy' => 'Wolf', 'position' => [30, 5]]]]);
  $composer->moveVertical(2);
  expect($composer->confirm())->toBe(TestTroopComposer::FIGHT);
  $composer->moveVertical(1);
  expect($composer->confirm())->toBe(TestTroopComposer::KEEP)
    ->and(testTroopComposer(keeps: false)->rows)->toBe(['add']);
});

it('moves a member on the ground with every other member pinned where it stood, and puts it back on cancel', function () {
  $composer = testTroopComposer(troop: null);
  foreach ([0, 1] as $ignored) {
    $composer->moveVertical(5);
    $composer->moveVertical(-5);
    $composer->moveVertical(count($composer->troop->members));
    $composer->confirm();
    $composer->confirm();
  }
  $composer->moveVertical(-5);
  $composer->confirm();
  expect($composer->actions)->toBe(['move', 'remove']);
  $composer->confirm();
  expect($composer->focus)->toBe(TestTroopComposer::PLACING);

  $composer->moveHorizontal(1);
  $composer->moveVertical(-1);
  $composer->moveVertical(1);
  $composer->moveVertical(1);
  $composer->moveVertical(1);
  $composer->moveVertical(1);
  [$moved, $other] = $composer->troop->getPlaces();
  // Across one step, forward to the near edge and no further; the other member keeps its arranged place.
  expect([$moved->x, $moved->depth])->toBe([115.0, 0.0])->and([$other->x, $other->depth])->toBe([300.0, 40.0]);
  $composer->cancel();
  expect($composer->troop->getPlaces())->toBe([null, null])->and($composer->focus)->toBe(TestTroopComposer::MEMBERS);

  // Without a graphical battle there is nothing to move; a member can still be removed.
  $terminal = testTroopComposer(graphical: false);
  $terminal->confirm();
  $terminal->confirm();
  $terminal->moveVertical(-1);
  $terminal->confirm();
  expect($terminal->actions)->toBe(['remove']);
  $terminal->confirm();
  expect($terminal->troop->members)->toBe([])->and($terminal->rows)->toBe(['add'])
    ->and($terminal->cancel())->toBe(TestTroopComposer::LEAVE);
});

it('opens the composer from the row after the troops and asks the arena to fight or keep its troop', function () {
  $editor = new ArenaSetupEditor(new BattleTestSetup([new BattleTestMember('hero', 5)]), 2, ['hero'],
    static fn(): array => [], static fn(): array => [], static fn(): int => 99, arenaKeys: ['arena.road'],
    composer: testTroopComposer());
  $editor->moveVertical(2);
  expect($editor->onComposeRow)->toBeTrue()->and($editor->troopRows)->toBe(3)->and($editor->confirm())->toBeNull()
    ->and($editor->focus)->toBe(ArenaSetupEditor::COMPOSER);

  // Left and right still choose the arena while composing.
  $editor->moveHorizontal(1);
  expect($editor->setup->arena)->toBe('arena.road');
  $editor->confirm();
  $editor->confirm();
  $editor->moveVertical(2);
  expect($editor->confirm())->toBe(ArenaSetupEditor::FIGHT_TEST);
  $editor->moveVertical(1);
  expect($editor->confirm())->toBe(ArenaSetupEditor::KEEP);

  // A kept troop joins the list; the cursor stays on composing, and cancel steps back out to the troops.
  $editor->setTroopCount(3);
  $editor->cancel();
  expect($editor->focus)->toBe(ArenaSetupEditor::TROOPS)->and($editor->troopIndex)->toBe(3)->and($editor->onComposeRow)->toBeTrue();
});
