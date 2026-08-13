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
