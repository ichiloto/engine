<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
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
