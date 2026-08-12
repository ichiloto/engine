<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\UI\Windows\SaveSlotWindow;

/**
 * Prepares a quiet console buffer for save-slot rendering assertions.
 */
function prepareSaveSlotConsole(): ReflectionClass
{
  $reflection = new ReflectionClass(Console::class);

  foreach ([
    'width' => 80,
    'height' => 8,
    'buffer' => [],
    'frameDepth' => 0,
    'frameBuffer' => '',
    'terminalHandedBack' => false,
    'output' => null,
  ] as $name => $value) {
    $reflection->getProperty($name)->setValue(null, $value);
  }

  return $reflection;
}

function makeSaveSlotWindowRecord(): SaveSlot
{
  return new SaveSlot(
    slot: 5,
    path: '/tmp/file5.iedata',
    isEmpty: false,
    locationName: 'Town Center',
    leaderName: 'Kaelion',
    leaderLevel: 1,
    playTimeSeconds: 344,
  );
}

it('highlights save metadata while keeping structural window chrome neutral', function () {
  $console = prepareSaveSlotConsole();
  $window = new SaveSlotWindow(new Vector2(0, 0), 60);
  $window->setSlot(makeSaveSlotWindowRecord(), true);

  ob_start();
  $window->render();
  ob_end_clean();

  $rows = $console->getProperty('buffer')->getValue();
  $top = TerminalText::visibleSymbols($rows[0]);
  $location = TerminalText::visibleSymbols($rows[1]);
  $footer = TerminalText::visibleSymbols($rows[2]);
  $bottom = TerminalText::visibleSymbols($rows[3]);
  $selectionColor = Color::LIGHT_BLUE->value;

  expect(TerminalText::stripAnsi($rows[0]))->toStartWith('╔═File 5')
    ->and($top[0])->not->toContain("\033[")
    ->and($top[1])->not->toContain("\033[")
    ->and($top[2])->toContain($selectionColor)
    ->and($location[0])->not->toContain("\033[")
    ->and($location[1])->not->toContain("\033[")
    ->and($location[2])->toContain($selectionColor)
    ->and($footer[0])->not->toContain("\033[")
    ->and($footer[array_key_last($footer)])->not->toContain("\033[")
    ->and($rows[2])->toContain($selectionColor . '0')
    ->and(implode('', array_map(TerminalText::stripAnsi(...), $bottom)))->toStartWith('╚')
    ->and($rows[3])->not->toContain($selectionColor);
});

it('removes every selection style when a reusable slot window loses focus', function () {
  $console = prepareSaveSlotConsole();
  $window = new SaveSlotWindow(new Vector2(0, 0), 60);
  $slot = makeSaveSlotWindowRecord();

  ob_start();
  $window->setSlot($slot, true);
  $window->render();
  $window->setSlot($slot, false);
  $window->render();
  ob_end_clean();

  $rows = array_slice($console->getProperty('buffer')->getValue(), 0, SaveSlotWindow::HEIGHT);

  expect(implode('', $rows))->not->toContain("\033[")
    ->and(array_map(TerminalText::stripAnsi(...), $rows))->toBe([
      '╔═File 5' . str_repeat('═', 51) . '╗' . str_repeat(' ', 20),
      '║ Town Center' . str_repeat(' ', 46) . '║' . str_repeat(' ', 20),
      '║ Kaelion Lv 1' . str_repeat(' ', 36) . '00:05:44 ║' . str_repeat(' ', 20),
      '╚' . str_repeat('═', 58) . '╝' . str_repeat(' ', 20),
    ]);
});
