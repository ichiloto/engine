<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\UI\BattleCommandContextWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Windows\WindowPadding;

class BattleCommandContextWindowTestProxy extends BattleCommandContextWindow
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal rendering for submenu scrolling tests.
  }

  public function getScrollOffset(): int
  {
    return $this->scrollOffset;
  }
}

function setBattleTestProperty(object $object, string $property, mixed $value): void
{
  $reflection = new ReflectionObject($object);

  while (! $reflection->hasProperty($property)) {
    $reflection = $reflection->getParentClass();

    if (! $reflection) {
      throw new RuntimeException("Property {$property} not found.");
    }
  }

  $reflectionProperty = $reflection->getProperty($property);
  $reflectionProperty->setAccessible(true);
  $reflectionProperty->setValue($object, $value);
}

it('scrolls battle submenu options when the list exceeds the viewport', function () {
  $screen = (new ReflectionClass(BattleScreen::class))->newInstanceWithoutConstructor();
  $selectionColor = (new ReflectionClass(BattleScreen::class))->getProperty('selectionColor');
  $selectionColor->setAccessible(true);
  $selectionColor->setValue($screen, Color::LIGHT_BLUE);

  $window = (new ReflectionClass(BattleCommandContextWindowTestProxy::class))->newInstanceWithoutConstructor();
  setBattleTestProperty($window, 'battleScreen', $screen);
  setBattleTestProperty($window, 'width', BattleCommandContextWindow::WIDTH);
  setBattleTestProperty($window, 'height', BattleCommandContextWindow::HEIGHT);
  setBattleTestProperty($window, 'padding', new WindowPadding(rightPadding: 1, leftPadding: 1));

  $items = [];

  foreach (['Fire', 'Ice', 'Bolt', 'Quake', 'Aero'] as $name) {
    $items[] = new BattleCommandOption(
      $name,
      "{$name} spell",
      new AttackAction($name),
      ItemScopeSide::ENEMY,
      ItemScopeStatus::ALIVE
    );
  }

  $window->setItems($items, 'Magic');
  $window->focus();
  $window->selectNext();
  $window->selectNext();
  $window->selectNext();
  $window->selectNext();

  expect($window->getActiveItem()?->label)->toBe('Aero')
    ->and($window->getScrollOffset())->toBe(1)
    ->and($window->getTitle())->toContain('2/2');
});
