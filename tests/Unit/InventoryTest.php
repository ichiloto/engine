<?php

use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;

/**
 * Creates a potion item for inventory tests.
 *
 * @param int $quantity The stack quantity.
 * @return Item The item.
 */
function makeInventoryTestItem(string $name = 'Potion', int $quantity = 1): Item
{
  return new Item($name, 'Restores a little HP.', '🧪', 50, $quantity);
}

it('removes one unit from a stack instead of deleting the whole stack', function () {
  $inventory = new Inventory();
  $inventory->addItems(makeInventoryTestItem('Potion', 3));

  $inventory->removeItems(makeInventoryTestItem('Potion'));

  expect($inventory->getQuantityByName('Potion'))->toBe(2);
});

it('removes the stack only once its quantity reaches zero', function () {
  $inventory = new Inventory();
  $inventory->addItems(makeInventoryTestItem('Potion', 3));

  $inventory->removeItems(makeInventoryTestItem('Potion'), makeInventoryTestItem('Potion'));
  expect($inventory->getQuantityByName('Potion'))->toBe(1)
    ->and($inventory->isEmpty)->toBeFalse();

  $inventory->removeItems(makeInventoryTestItem('Potion'));
  expect($inventory->getQuantityByName('Potion'))->toBe(0)
    ->and($inventory->isEmpty)->toBeTrue();
});

it('keeps processing the remaining items after removing from one stack', function () {
  $inventory = new Inventory();
  $inventory->addItems(makeInventoryTestItem('Potion', 2));
  $inventory->addItems(makeInventoryTestItem('Ether', 2));

  $inventory->removeItems(makeInventoryTestItem('Potion'), makeInventoryTestItem('Ether'));

  expect($inventory->getQuantityByName('Potion'))->toBe(1)
    ->and($inventory->getQuantityByName('Ether'))->toBe(1);
});

it('ignores removals of items that are not in the inventory', function () {
  $inventory = new Inventory();
  $inventory->addItems(makeInventoryTestItem('Potion', 2));

  $inventory->removeItems(makeInventoryTestItem('Elixir'));

  expect($inventory->getQuantityByName('Potion'))->toBe(2)
    ->and($inventory->all->count())->toBe(1);
});
