<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\QuantitySelector;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\PurchaseConfirmationMode;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\UI\Modal\QuantityModal;

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

it('saturates integer-extreme adjustments before arithmetic can overflow', function () {
  $selector = new QuantitySelector(maximum: PHP_INT_MAX, initial: PHP_INT_MAX - 2);
  expect($selector->adjustForAxes(0, 1))->toBeTrue()
    ->and($selector->quantity)->toBe(PHP_INT_MAX)
    ->and($selector->adjust(PHP_INT_MAX))->toBeFalse()
    ->and($selector->adjust(PHP_INT_MIN))->toBeTrue()
    ->and($selector->quantity)->toBe(1)
    ->and($selector->adjust(PHP_INT_MIN))->toBeFalse()
    ->and($selector->adjust(PHP_INT_MAX))->toBeTrue()
    ->and($selector->quantity)->toBe(PHP_INT_MAX);
});

it('rejects invalid quantity-selection contracts', function (int $minimum, int $maximum, int $step) {
  new QuantitySelector(maximum: $maximum, minimum: $minimum, coarseStep: $step);
})->with([
  'negative minimum' => [-1, 10, 10],
  'maximum below minimum' => [5, 4, 10],
  'non-positive coarse step' => [1, 10, 0],
])->throws(InvalidArgumentException::class);

it('uses the shared selector in both shops and modal quantity selection', function () {
  $shopState = (new ReflectionClass(ShopState::class))->newInstanceWithoutConstructor();
  $shopMode = new PurchaseConfirmationMode($shopState);
  $shopSelector = (new ReflectionProperty($shopMode, 'quantitySelector'))->getValue($shopMode);

  $eventManager = new ReflectionProperty(\Ichiloto\Engine\Events\EventManager::class, 'instance');
  $previous = $eventManager->getValue();
  try {
    $game = new class extends Game {
      public function __construct() {}
      public function __destruct() {}
    };
    $modal = new QuantityModal($game, 'Choose amount', 14);
    $modalSelector = new ReflectionProperty($modal, 'selector')->getValue($modal);

    expect($shopSelector)->toBeInstanceOf(QuantitySelector::class)
      ->and($modalSelector)->toBeInstanceOf(QuantitySelector::class)
      ->and($modal->maximum)->toBe(14)
      ->and($modal->quantity)->toBe(1);
  } finally {
    $eventManager->setValue(null, $previous);
  }
});
