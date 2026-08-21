<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * Creates an ItemStore without touching the filesystem-backed catalog.
 *
 * @return ItemStore The item store.
 */
function makeEmptyItemStore(): ItemStore
{
  return (new ReflectionClass(ItemStore::class))->newInstanceWithoutConstructor();
}

afterEach(function () {
  ConfigStore::remove(ItemStore::class);
});

it('loads clones so party items never alias the catalog singletons', function () {
  $store = makeEmptyItemStore();
  $store->set('Potion', new Item('Potion', 'Restores a little HP.', '🧪', 50));
  ConfigStore::put(ItemStore::class, $store);

  $loaded = $store->load([
    ['item' => 'Potion', 'quantity' => 2],
  ]);

  expect($loaded)->toHaveCount(2)
    ->and($loaded[0])->not->toBe($store->get('Potion'))
    ->and($loaded[1])->not->toBe($store->get('Potion'))
    ->and($loaded[0])->not->toBe($loaded[1]);

  $catalogQuantity = $store->get('Potion')->quantity;
  $loaded[0]->quantity += 10;

  expect($store->get('Potion')->quantity)->toBe($catalogQuantity);
});

it('instantiates runtime item grants by stable catalog name', function () {
  $store = makeEmptyItemStore();
  $store->set('Potion', new Item('Potion', 'Restores a little HP.', '🧪', 50));

  $items = $store->instantiate('Potion', 2);

  expect($items)->toHaveCount(2)
    ->and($items[0]->name)->toBe('Potion')
    ->and($items[0])->not->toBe($store->get('Potion'))
    ->and($items[1])->not->toBe($items[0])
    ->and($store->instantiate('Potion', 0))->toBe([])
    ->and(fn() => $store->instantiate('Missing Item'))
    ->toThrow(Ichiloto\Engine\Exceptions\NotFoundException::class);
});

it('resolves stable id current display name and declared alias to one owned definition', function () {
  $store = makeEmptyItemStore();
  $definition = new Item(
    'Current Tonic',
    'Current definition',
    '!',
    10,
    id: 'item.tonic',
    aliases: ['Old Tonic'],
  );
  $store->set($definition->id, $definition);
  ConfigStore::put(ItemStore::class, $store);
  $inventory = new Ichiloto\Engine\Entities\Inventory\Inventory();
  $inventory->addItems(clone $definition, clone $definition);

  expect($store->definitionIdFor('item.tonic'))->toBe('item.tonic')
    ->and($store->definitionIdFor('Current Tonic'))->toBe('item.tonic')
    ->and($store->definitionIdFor('Old Tonic'))->toBe('item.tonic')
    ->and($store->displayNameFor('Old Tonic'))->toBe('Current Tonic')
    ->and($inventory->getQuantity('item.tonic'))->toBe(2)
    ->and($inventory->getQuantity('Current Tonic'))->toBe(2)
    ->and($inventory->getQuantity('Old Tonic'))->toBe(2)
    ->and($inventory->consumeReference('Old Tonic', 1))->toBeTrue()
    ->and($inventory->getQuantity('item.tonic'))->toBe(1);
});

it('fails unknown inventory references with the consumer context', function () {
  $store = makeEmptyItemStore();
  ConfigStore::put(ItemStore::class, $store);

  expect(fn() => $store->requireDefinitionId('Removed Tonic', 'checking a quest objective'))
    ->toThrow(
      Ichiloto\Engine\Exceptions\NotFoundException::class,
      'Inventory reference "Removed Tonic" while checking a quest objective',
    );
});
