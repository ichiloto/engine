<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

it('keeps stable animation ids independent of skill names and subtypes', function (string $class) {
  $skill = new $class('Renamed skill', '', '', 0, 0, animationId: 73);
  expect($skill->animationId)->toBe(73)
    ->and((clone $skill)->animationId)->toBe(73)
    ->and(unserialize(serialize($skill))->animationId)->toBe(73)
    ->and(new $class('Legacy', '', '', 0, 0)->animationId)->toBeNull();
})->with([BasicSkill::class, MagicSkill::class, SpecialSkill::class]);

it('defaults old skill objects without an animation property to legacy selection', function () {
  $class = BasicSkill::class;
  $legacy = unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));
  expect($legacy->animationId)->toBeNull();
});

it('hydrates optional item animation ids and preserves them when cloning', function () {
  $payload = ['name' => 'Potion', 'description' => '', 'icon' => '', 'price' => 1, 'animationId' => 73,
    'scope' => new \Ichiloto\Engine\Entities\Inventory\Items\ItemScope()];
  $item = Item::fromArray($payload);
  expect($item->animationId)->toBe(73)
    ->and((clone $item)->animationId)->toBe(73)
    ->and(Item::fromObject((object) $payload)->animationId)->toBe(73);
  unset($payload['animationId']);
  expect(Item::fromArray($payload)->animationId)->toBeNull();
});

it('restores item animation references from the current definition rather than frozen save data', function () {
  $store = new ReflectionClass(ItemStore::class)->newInstanceWithoutConstructor();
  $original = new Item('Potion', '', '', 1, id: 'item.potion', animationId: 1);
  $store->set('item.potion', $original);
  ConfigStore::put(ItemStore::class, $store);
  try {
    $saved = serialize($original);
    $store->set('item.potion', new Item('Potion', '', '', 1, id: 'item.potion', animationId: 73));
    expect(unserialize($saved)->animationId)->toBe(73);
  } finally {
    ConfigStore::remove(ItemStore::class);
  }
});
