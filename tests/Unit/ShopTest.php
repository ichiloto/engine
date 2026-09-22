<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Shop\Shop;

it('rejects a sale when the party owns fewer items than offered', function () {
  $party = new Party();
  $party->addItems(new Item('Potion', 'Restores a little HP.', '🧪', 50, 2));

  $shop = new Shop();
  $shop->buy(new Item('Potion', 'Restores a little HP.', '🧪', 50), 3, $party);

  expect($party->accountBalance)->toBe(0)
    ->and($party->inventory->getQuantityByName('Potion'))->toBe(2);
});

it('rejects selling key items to the shop', function () {
  $party = new Party();
  $keyItem = new Item('Castle Key', 'Opens the castle gate.', '🗝️', 0, 1, isKeyItem: true);
  $party->addItems($keyItem);

  $shop = new Shop();
  $shop->buy($keyItem, 1, $party);

  expect($party->accountBalance)->toBe(0)
    ->and($party->inventory->getQuantityByName('Castle Key'))->toBe(1);
});

it('credits an integer payout for a valid sale', function () {
  $party = new Party();
  $party->addItems(new Item('Potion', 'Restores a little HP.', '🧪', 15, 2));

  $shop = new Shop(traderSellRate: 0.5);
  $shop->buy(new Item('Potion', 'Restores a little HP.', '🧪', 15), 1, $party);

  expect($party->accountBalance)->toBeInt()
    ->toBe(8) // (int) round(15 * 1 * 0.5)
    ->and($party->inventory->getQuantityByName('Potion'))->toBe(1);
});

it('enforces the owned sale policy even when a same-id offer spoofs its metadata', function (string $class, bool $key, bool $sellable) {
  $party = new Party();
  $owned = new $class('Restricted item', '', '', 350, 2, isKeyItem: $key,
    id: 'equipment.restricted', sellable: $sellable);
  $party->addItems($owned);
  $party->credit(25);
  $offer = new Item('Ordinary item', '', '', 9999, 99, id: $owned->id, sellable: true);
  $shop = new Shop();

  foreach ([$owned, $offer] as $item) {
    $shop->buy($item, 1, $party);
    expect($party->accountBalance)->toBe(25)
      ->and($owned->quantity)->toBe(2)
      ->and($party->inventory->all->toArray())->toBe([$owned]);
  }
  expect($owned->isSellable)->toBeFalse()->and($owned->sellValue)->toBe(0);
})->with([
  'unsellable consumable' => [Item::class, false, false],
  'unsellable issued weapon' => [Weapon::class, false, false],
  'key item with sale flag' => [Item::class, true, true],
]);

it('uses the owned price while preserving shop rates and transaction rounding', function (int $price, int $quantity, float $rate, int $payout) {
  $party = new Party();
  $owned = new Weapon('Conventional weapon', '', '', $price, 2, id: 'equipment.conventional');
  $party->addItems($owned);
  $offer = new Item('Spoofed offer', '', '', 9999, 99, isKeyItem: true,
    id: $owned->id, sellable: false);

  (new Shop(traderSellRate: $rate))->buy($offer, $quantity, $party);

  expect($party->accountBalance)->toBe($payout)
    ->and($owned->quantity)->toBe(2 - $quantity)
    ->and($offer->quantity)->toBe(99)
    ->and($party->inventory->all->toArray())->toBe($quantity === 2 ? [] : [$owned]);
})->with([
  '650 at fifty percent' => [650, 1, 0.5, 325],
  '550 at fifty percent' => [550, 1, 0.5, 275],
  '700 at fifty percent' => [700, 1, 0.5, 350],
  '325 at fifty percent' => [325, 1, 0.5, 163],
  '275 at fifty percent' => [275, 1, 0.5, 138],
  '350 at fifty percent' => [350, 1, 0.5, 175],
  'round the transaction once' => [325, 2, 0.5, 325],
  'custom shop rate' => [325, 1, 0.8, 260],
  'zero shop rate' => [325, 1, 0.0, 0],
]);

it('rejects invalid quantities and unowned stable ids without side effects', function (int $quantity, string $offeredId) {
  $party = new Party();
  $owned = new Item('Potion', '', '', 50, 2, id: 'item.potion');
  $party->addItems($owned);
  (new Shop())->buy(new Item('Potion', '', '', 50, id: $offeredId), $quantity, $party);
  expect($party->accountBalance)->toBe(0)->and($owned->quantity)->toBe(2)
    ->and($party->inventory->all->toArray())->toBe([$owned]);
})->with([
  'zero quantity' => [0, 'item.potion'],
  'negative quantity' => [-1, 'item.potion'],
  'same name but unowned id' => [1, 'item.other-potion'],
]);
