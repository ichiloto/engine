<?php

use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Battle\DropItem;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

beforeEach(function () {
  $store = (new ReflectionClass(ItemStore::class))->newInstanceWithoutConstructor();
  ConfigStore::put(ItemStore::class, $store);
});

afterEach(function () {
  ConfigStore::remove(ItemStore::class);
});

it('drops nothing when no candidate passes its drop-rate roll', function () {
  $herb = new Item('Herb', 'A fragrant healing herb.', '🌿', 10);
  $rewards = new BattleRewards(10, 5, [new DropItem($herb, 0.0)]);

  expect($rewards->item)->toBeNull();
});

it('drops the item when its drop rate always passes', function () {
  $herb = new Item('Herb', 'A fragrant healing herb.', '🌿', 10);
  $rewards = new BattleRewards(10, 5, [new DropItem($herb, 1.0)]);

  expect($rewards->item)->toBe($herb);
});

it('drops nothing when there are no drop candidates', function () {
  $rewards = new BattleRewards(10, 5, []);

  expect($rewards->item)->toBeNull();
});
