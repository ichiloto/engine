<?php

use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectItemTargetMode;
use Ichiloto\Engine\Core\Menu\QuantitySelector;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\PurchaseConfirmationMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\Game\States\ShopState;

class QuantitySelectingItemMode extends SelectItemTargetMode
{
  public function __construct(ItemMenuState $state)
  {
    parent::__construct($state);
  }

  public function start(Item $item, Character $target): void
  {
    $this->beginQuantitySelection($item, $target);
  }

  public function selector(): ?QuantitySelector
  {
    return $this->quantitySelector;
  }

  protected function updateQuantitySelectionContent(): void
  {
    // Rendering is deliberately excluded: this proxy verifies mode adoption
    // of the shared interaction contract without constructing the full menu.
  }
}

it('clamps quantities to the workflow bounds', function () {
  $selector = new QuantitySelector(maximum: 12, initial: 4);

  expect($selector->quantity)->toBe(4)
    ->and($selector->set(99))->toBeTrue()
    ->and($selector->quantity)->toBe(12)
    ->and($selector->set(13))->toBeFalse()
    ->and($selector->set(-5))->toBeTrue()
    ->and($selector->quantity)->toBe(1);
});

it('provides one fine and coarse axis contract for stack-based menus', function () {
  $selector = new QuantitySelector(maximum: 99, initial: 20);

  expect($selector->axisAdjustment(-1, 0))->toBe(1)
    ->and($selector->axisAdjustment(1, 0))->toBe(-1)
    ->and($selector->axisAdjustment(0, 1))->toBe(10)
    ->and($selector->axisAdjustment(0, -1))->toBe(-10)
    ->and($selector->adjustForAxes(-1, 0))->toBeTrue()
    ->and($selector->quantity)->toBe(21)
    ->and($selector->adjustForAxes(0, 1))->toBeTrue()
    ->and($selector->quantity)->toBe(31);
});

it('does not report a change when an axis adjustment is already at a bound', function () {
  $selector = new QuantitySelector(maximum: 10, initial: 10);

  expect($selector->adjustForAxes(0, 1))->toBeFalse()
    ->and($selector->quantity)->toBe(10);
});

it('rejects invalid quantity-selection contracts', function (int $minimum, int $maximum, int $step) {
  new QuantitySelector(maximum: $maximum, minimum: $minimum, coarseStep: $step);
})->with([
  'negative minimum' => [-1, 10, 10],
  'maximum below minimum' => [5, 4, 10],
  'non-positive coarse step' => [1, 10, 0],
])->throws(InvalidArgumentException::class);

it('uses the shared selector in both shops and field-item consumption', function () {
  $shopState = (new ReflectionClass(ShopState::class))->newInstanceWithoutConstructor();
  $shopMode = new PurchaseConfirmationMode($shopState);
  $shopSelector = (new ReflectionProperty($shopMode, 'quantitySelector'))->getValue($shopMode);

  $itemState = (new ReflectionClass(ItemMenuState::class))->newInstanceWithoutConstructor();
  $itemMode = new QuantitySelectingItemMode($itemState);
  $item = new Item('S-Potion', 'Restores HP.', '', 10, quantity: 14);
  $target = new Character('Kaelion', 0, new Stats());
  $itemMode->start($item, $target);

  expect($shopSelector)->toBeInstanceOf(QuantitySelector::class)
    ->and($itemMode->selector())->toBeInstanceOf(QuantitySelector::class)
    ->and($itemMode->selector()?->maximum)->toBe(14);
});
