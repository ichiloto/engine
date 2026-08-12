<?php

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Battle\UI\BattleMessageWindow;
use Ichiloto\Engine\IO\Enumerations\Color;

class BattleMessageWindowLifecycleTestProxy extends BattleMessageWindow
{
  public int $hideCount = 0;

  public function hide(): void
  {
    $this->hideCount++;
  }
}

class BattleScreenLifecycleTestProxy extends BattleScreen
{
  public int $recomposeCount = 0;

  public function recomposeField(): void
  {
    $this->recomposeCount++;
  }
}

it('styles battle selection lines with the configured highlight color', function () {
  $reflection = new ReflectionClass(BattleScreen::class);
  $screen = $reflection->newInstanceWithoutConstructor();

  $selectionColor = $reflection->getProperty('selectionColor');
  $selectionColor->setValue($screen, Color::LIGHT_BLUE);

  $styledLine = $screen->styleSelectionLine('> Attack');

  expect($styledLine)->toContain(Color::LIGHT_BLUE->value)
    ->and($styledLine)->toContain('> Attack')
    ->and($styledLine)->toEndWith(Color::RESET->value);
});

it('can blink the active battle selection line', function () {
  $reflection = new ReflectionClass(BattleScreen::class);
  $screen = $reflection->newInstanceWithoutConstructor();

  $selectionColor = $reflection->getProperty('selectionColor');
  $selectionColor->setValue($screen, Color::LIGHT_BLUE);

  $styledLine = $screen->styleSelectionLine('> Kaelion', blink: true);

  expect($styledLine)->toContain("\033[5m")
    ->and($styledLine)->toContain(Color::LIGHT_BLUE->value)
    ->and($styledLine)->toContain('> Kaelion');
});

it('restores the battlefield when a visible battle message is hidden', function () {
  $screenReflection = new ReflectionClass(BattleScreenLifecycleTestProxy::class);
  $screen = $screenReflection->newInstanceWithoutConstructor();
  $message = (new ReflectionClass(BattleMessageWindowLifecycleTestProxy::class))->newInstanceWithoutConstructor();

  (new ReflectionProperty(BattleScreen::class, 'messageWindow'))->setValue($screen, $message);
  (new ReflectionProperty(BattleScreen::class, 'isMessageVisible'))->setValue($screen, true);

  $screen->hideMessage();
  $screen->hideMessage();

  expect($message->hideCount)->toBe(1)
    ->and($screen->recomposeCount)->toBe(1);
});
