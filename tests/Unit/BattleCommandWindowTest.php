<?php

use Ichiloto\Engine\Battle\UI\BattleCommandWindow;

class BattleCommandWindowTestProxy extends BattleCommandWindow
{
  public function updateContent(): void
  {
    // Skip terminal rendering for this focused unit test.
  }

  public function setTotalCommands(int $totalCommands): void
  {
    $this->totalCommands = $totalCommands;
  }

  public function isBlinkingSelection(): bool
  {
    return $this->blinkActiveSelection;
  }
}

it('blinks the active command only while the command window is focused', function () {
  $window = (new ReflectionClass(BattleCommandWindowTestProxy::class))->newInstanceWithoutConstructor();
  $window->setTotalCommands(4);

  $window->focus();

  expect($window->activeCommandIndex)->toBe(0)
    ->and($window->isBlinkingSelection())->toBeTrue();

  $window->blur();

  expect($window->activeCommandIndex)->toBe(-1)
    ->and($window->isBlinkingSelection())->toBeFalse();
});
