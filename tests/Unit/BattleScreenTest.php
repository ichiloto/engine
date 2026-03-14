<?php

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\IO\Enumerations\Color;

it('styles battle selection lines with the configured highlight color', function () {
  $reflection = new ReflectionClass(BattleScreen::class);
  $screen = $reflection->newInstanceWithoutConstructor();

  $selectionColor = $reflection->getProperty('selectionColor');
  $selectionColor->setAccessible(true);
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
  $selectionColor->setAccessible(true);
  $selectionColor->setValue($screen, Color::LIGHT_BLUE);

  $styledLine = $screen->styleSelectionLine('> Kaelion', blink: true);

  expect($styledLine)->toContain("\033[5m")
    ->and($styledLine)->toContain(Color::LIGHT_BLUE->value)
    ->and($styledLine)->toContain('> Kaelion');
});
