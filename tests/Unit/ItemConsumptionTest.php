<?php

use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Stats;

/**
 * Builds a healing item with the given stack size.
 */
function makeHealingItem(string $name = 'S-Potion', int $quantity = 3, bool $consumable = true): Item
{
  $item = new Item($name, 'Restores 50 HP.', '🧪', 50, consumable: $consumable, effects: [
    new HPRecoveryEffect('Recover 50 HP', 'Recovers 50 HP', 50, 100, ValueBasis::ACTUAL),
  ]);
  $item->quantity = $quantity;

  return $item;
}

/**
 * Builds a wounded party member that item effects can act on.
 */
function makeWoundedBattler(int $currentHp = 100): Character
{
  $stats = new Stats(
    totalHp: 500,
    totalMp: 100,
    attack: 10,
    defence: 10,
    magicAttack: 10,
    magicDefence: 10,
    speed: 10,
    grace: 10,
    evasion: 5
  );
  $stats->currentHp = $currentHp;

  return new Character('Hero', 0, $stats);
}

it('marks items consumable by default', function () {
  // The parent InventoryItem constructor must not reset this back to its own
  // non-consumable default, or battle item use silently stops costing stock.
  expect(new Item('S-Potion', 'Restores HP.', '🧪', 50)->consumable)->toBeTrue();
});

it('honours an explicit non-consumable item', function () {
  expect(new Item('Castle Key', 'Opens the gate.', '🗝️', 0, consumable: false)->consumable)->toBeFalse();
});

it('keeps equipment non-consumable', function () {
  // Equipment threads $consumable through to the parent correctly; this
  // guards the sibling of the bug that made every Item non-consumable.
  expect(new Weapon('Iron Sword', 'A plain blade.', '🗡️', 100)->consumable)->toBeFalse();
});

it('reduces the party stock when a battler uses an item in battle', function () {
  $item = makeHealingItem(quantity: 3);
  $inventory = new Inventory();
  $inventory->addItems($item);
  $battler = makeWoundedBattler();

  new ItemBattleAction($item, $inventory)->execute($battler, [$battler]);

  expect($inventory->getQuantityByName('S-Potion'))->toBe(2)
    ->and($item->quantity)->toBe(2)
    ->and($battler->stats->currentHp)->toBe(150);
});

it('drops the stack from the inventory once the last one is used in battle', function () {
  $item = makeHealingItem(quantity: 1);
  $inventory = new Inventory();
  $inventory->addItems($item);
  $battler = makeWoundedBattler();

  new ItemBattleAction($item, $inventory)->execute($battler, [$battler]);

  // A depleted stack must not linger as a phantom "x0" entry in the menus.
  expect($inventory->getQuantityByName('S-Potion'))->toBe(0)
    ->and($inventory->items->count())->toBe(0);
});

it('does nothing when the stack is already empty', function () {
  $item = makeHealingItem(quantity: 0);
  $inventory = new Inventory();
  $battler = makeWoundedBattler();

  new ItemBattleAction($item, $inventory)->execute($battler, [$battler]);

  expect($battler->stats->currentHp)->toBe(100)
    ->and($item->quantity)->toBe(0);
});

it('never consumes a non-consumable item in battle', function () {
  $item = makeHealingItem('Everlasting Charm', quantity: 1, consumable: false);
  $inventory = new Inventory();
  $inventory->addItems($item);
  $battler = makeWoundedBattler();

  new ItemBattleAction($item, $inventory)->execute($battler, [$battler]);

  expect($inventory->getQuantityByName('Everlasting Charm'))->toBe(1)
    ->and($battler->stats->currentHp)->toBe(150);
});

it('still decrements when the action has no inventory to consume through', function () {
  $item = makeHealingItem(quantity: 2);
  $battler = makeWoundedBattler();

  new ItemBattleAction($item)->execute($battler, [$battler]);

  expect($item->quantity)->toBe(1);
});

it('does not consume stock when the user is knocked out', function () {
  $item = makeHealingItem(quantity: 2);
  $inventory = new Inventory();
  $inventory->addItems($item);
  $battler = makeWoundedBattler(currentHp: 0);

  new ItemBattleAction($item, $inventory)->execute($battler, [$battler]);

  expect($inventory->getQuantityByName('S-Potion'))->toBe(2);
});
