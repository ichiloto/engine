<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Shop\Shop;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

final class ShopPurchaseAlerts extends ModalManager
{
  public array $messages = [];
  public function __construct() {}
  public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    $this->messages[] = $message;
  }
}

beforeEach(function () {
  $this->consoleGameBefore = new ReflectionProperty(Console::class, 'game')->getValue();
  $this->modalBefore = new ReflectionProperty(ModalManager::class, 'instance')->getValue();
  $this->configBefore = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  new ReflectionProperty(Console::class, 'game')->setValue(null, $game);
  $this->alerts = new ShopPurchaseAlerts();
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->alerts);
  putSceneAudioConfig([]);
});

afterEach(function () {
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->consoleGameBefore);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->modalBefore);
  foreach ($this->configBefore as $name => $value) {
    new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value);
  }
});

it('keeps buy sell and rebuy stacks independent of catalogue quantities and other parties', function (string $class, int $prototypeQuantity) {
  $prototype = new $class('Staff or supply', '', '', 650, $prototypeQuantity, id: 'shop.definition');
  $store = new ReflectionClass(ItemStore::class)->newInstanceWithoutConstructor();
  $store->set($prototype->id, $prototype);
  $merchandise = $store->get($prototype->id);
  $shop = new Shop([$merchandise]);
  $party = new Party();
  $party->credit(8750);
  $other = new Party();
  $other->credit(8750);

  $shop->sell($merchandise, 1, $party);
  $first = $party->inventory->all->toArray()[0];
  $shop->sell($merchandise, 1, $other);
  $otherStack = $other->inventory->all->toArray()[0];
  expect($first)->not->toBe($merchandise)->not->toBe($prototype)->not->toBe($otherStack)
    ->and($first->quantity)->toBe(1)->and($first->id)->toBe($prototype->id);
  $shop->buy($first, 1, $party);
  expect($party->inventory->isEmpty)->toBeTrue()->and($first->quantity)->toBe(0)
    ->and($otherStack->quantity)->toBe(1)->and($merchandise->quantity)->toBe($prototypeQuantity);
  $shop->sell($merchandise, 1, $party);
  $rebought = $party->inventory->all->toArray()[0];
  expect($rebought)->not->toBe($first)->not->toBe($otherStack)->not->toBe($merchandise)
    ->and($rebought->quantity)->toBe(1)->and($rebought->id)->toBe($prototype->id)
    ->and($party->accountBalance)->toBe(7775)
    ->and($store->get($prototype->id)->quantity)->toBe($prototypeQuantity)
    ->and($prototype->quantity)->toBe($prototypeQuantity)
    ->and($shop->inventory->all->toArray())->toBe([$merchandise])
    ->and($this->alerts->messages)->toBe([]);
  if ($rebought instanceof Weapon) {
    $actor = new Character('Buyer', 0, new Stats());
    $party->addMember($actor);
    $actor->optimizeEquipment($party->inventory, $party);
    expect($actor->equipment[0]->equipment)->toBe($rebought);
  }
})->with([Item::class, Weapon::class])->with([0, 1, 7]);

it('admits exactly the requested units and preserves an existing stack even at slot capacity', function (string $class) {
  $party = new Party();
  new ReflectionProperty(Party::class, 'inventory')->setValue($party, new Inventory(capacity: 1));
  $party->credit(1000);
  $merchandise = new $class('Stackable', '', '', 25, 7, id: 'shop.stack');
  $shop = new Shop([$merchandise], traderBuyRate: 0.8);
  $shop->sell($merchandise, 3, $party);
  $owned = $party->inventory->all->toArray()[0];
  expect($owned->quantity)->toBe(3)->and($party->inventory->isFull)->toBeTrue();
  $shop->sell($merchandise, 2, $party);
  expect($party->inventory->all->toArray())->toBe([$owned])
    ->and($owned->quantity)->toBe(5)->and($merchandise->quantity)->toBe(7)
    ->and($party->accountBalance)->toBe(900)->and($this->alerts->messages)->toBe([]);
})->with([Item::class, Weapon::class]);

it('does not charge or partially admit an over-capacity purchase', function (string $class, bool $existing) {
  $party = new Party();
  $party->credit(1000);
  $merchandise = new $class('Limited stack', '', '', 1, 7, id: 'shop.limited');
  if ($existing) {
    $owned = clone $merchandise;
    $owned->quantity = $owned->maxQuantity - 1;
    $party->addItems($owned);
  }
  $before = $party->inventory->all->toArray();
  (new Shop([$merchandise]))->sell($merchandise, $existing ? 2 : $merchandise->maxQuantity + 1, $party);
  expect($party->accountBalance)->toBe(1000)->and($party->inventory->all->toArray())->toBe($before)
    ->and($merchandise->quantity)->toBe(7)
    ->and($this->alerts->messages)->toBe(['Not enough space in inventory!']);
  if ($existing) {
    expect($owned->quantity)->toBe($owned->maxQuantity - 1);
  }
})->with([Item::class, Weapon::class, Armor::class, Accessory::class])->with([false, true]);

it('rejects a new stack when inventory slots are full without charging', function (string $class) {
  $party = new Party();
  new ReflectionProperty(Party::class, 'inventory')->setValue($party, new Inventory(capacity: 1));
  $held = new Item('Existing', '', '', 1, 2);
  $party->addItems($held);
  $party->credit(1000);
  $merchandise = new $class('New stack', '', '', 100);
  (new Shop([$merchandise]))->sell($merchandise, 1, $party);
  expect($party->accountBalance)->toBe(1000)->and($party->inventory->all->toArray())->toBe([$held])
    ->and($held->quantity)->toBe(2)->and($merchandise->quantity)->toBe(1)
    ->and($this->alerts->messages)->toBe(['Not enough space in inventory!']);
})->with([Item::class, Weapon::class]);

it('ignores non-positive purchase quantities before any mutation or alert', function (int $quantity) {
  $party = new Party();
  $party->credit(1000);
  $merchandise = new Item('Supply', '', '', 100, 7);
  (new Shop([$merchandise]))->sell($merchandise, $quantity, $party);
  expect($party->accountBalance)->toBe(1000)->and($party->inventory->isEmpty)->toBeTrue()
    ->and($merchandise->quantity)->toBe(7)->and($this->alerts->messages)->toBe([]);
})->with([0, -1]);

it('leaves inventory and catalogue unchanged when the party cannot afford the quoted purchase', function () {
  $party = new Party();
  $party->credit(50);
  $merchandise = new Item('Supply', '', '', 30, 7);
  (new Shop([$merchandise]))->sell($merchandise, 2, $party);
  expect($party->accountBalance)->toBe(50)->and($party->inventory->isEmpty)->toBeTrue()
    ->and($merchandise->quantity)->toBe(7)->and($this->alerts->messages)->toBe(['Not enough Gold!']);
});
