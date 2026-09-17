<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Shop\Shop;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\InfoPanel;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\PurchaseConfirmationMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopInventorySelectionMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopMerchandiseSelectionMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Windows\ShopMainPanel;
use Ichiloto\Engine\Util\Config\ConfigStore;

it('lists only held saleable definitions and refreshes after the last eligible sale', function () {
  $party = new Party();
  $issued = new Weapon('BSA issue', '', '', 350, sellable: false);
  $key = new Item('Key', '', '', 100, isKeyItem: true);
  $locked = new Item('Quest sample', '', '', 100, sellable: false);
  $empty = new Item('Empty stack', '', '', 100, quantity: 0);
  $weapon = new Weapon('Conventional weapon', '', '', 325);
  $free = new Item('Free item', '', '', 0);
  $party->addItems($issued, $key, $locked, $empty, $weapon, $free);
  $scene = (new ReflectionClass(GameScene::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(GameScene::class, 'party')->setValue($scene, $party);
  $state = new ShopState(new SceneStateContext($scene));

  expect($state->sellableItems)->toBe([$weapon, $free])
    ->and($free->isSellable)->toBeTrue()->and($free->sellValue)->toBe(0);
  $shop = new Shop();
  $shop->buy($weapon, 1, $party);
  expect($state->sellableItems)->toBe([$free]);
  $shop->buy($free, 1, $party);
  expect($state->sellableItems)->toBe([])
    ->and($party->inventory->all->toArray())->toBe([$issued, $key, $locked, $empty]);
});

it('uses action-appropriate quantity help for buying and selling', function (bool $selling) {
  $before = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  try {
    putSceneAudioConfig([]);
    $state = new ReflectionClass(ShopState::class)->newInstanceWithoutConstructor();
    $info = new class extends InfoPanel {
      public string $text = '';
      public function __construct() {}
      public function setText(string $text): void { $this->text = $text; }
    };
    new ReflectionProperty(ShopState::class, 'infoPanel')->setValue($state, $info);
    new ReflectionProperty(ShopState::class, 'mainPanel')->setValue($state,
      new ReflectionClass(ShopMainPanel::class)->newInstanceWithoutConstructor());
    $mode = new class($state) extends PurchaseConfirmationMode {
      public function updateWindowContent(): void {}
    };
    $mode->previousMode = $selling ? new ShopInventorySelectionMode($state) : new ShopMerchandiseSelectionMode($state);
    $mode->enter();
    expect($info->text)->toBe('Use the arrow keys to adjust the quantity of the item to ' . ($selling ? 'sell.' : 'purchase.'));
  } finally {
    foreach ($before as $name => $value) {
      new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value);
    }
  }
})->with([false, true]);
