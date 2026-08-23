<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

/**
 * Prepares a console of the given size with an empty buffer.
 */
function withConsole(int $width, int $height): ReflectionClass
{
  $reflection = new ReflectionClass(Console::class);

  foreach ([
    ['width', $width],
    ['height', $height],
    ['buffer', []],
    ['frameDepth', 0],
    ['frameRows', []],
    ['recomposeRepaintRows', []],
    ['isRecomposing', false],
    ['terminalHandedBack', false],
  ] as [$name, $value]) {
    $reflection->getProperty($name)->setValue(null, $value);
  }

  return $reflection;
}

it('uses the DEC private autowrap mode understood by terminal emulators', function () {
  withConsole(20, 4);

  ob_start();
  Console::disableLineWrap();
  Console::enableLineWrap();
  $output = ob_get_clean();

  expect($output)->toBe("\033[?7l\033[?7h");
});

it('restores autowrap before handing the terminal screen back', function () {
  $console = withConsole(20, 4);
  $console->getProperty('usingAlternateScreen')->setValue(null, true);

  ob_start();
  Console::reset();
  $output = ob_get_clean();

  expect($output)->toStartWith("\033[?7h\033[?1049l")
    ->and($console->getProperty('terminalHandedBack')->getValue())->toBeTrue();
});

it('can repaint an unchanged canonical region without repainting the screen', function () {
  withConsole(20, 4);

  ob_start();
  Console::write('ABCDE', 2, 1);
  Console::write('12345', 2, 2);
  ob_end_clean();

  ob_start();
  Console::repaintRegion(2, 1, 5, 2);
  $output = ob_get_clean();

  expect($output)->toBe("\033[2;3HABCDE\033[3;3H12345")
    ->and($output)->not->toContain("\033[1;")
    ->and($output)->not->toContain("\033[4;");
});

it('preserves explicit region repairs through complete screen recomposition', function () {
  withConsole(20, 4);

  ob_start();
  Console::write('stable', 2, 1);
  ob_end_clean();

  ob_start();
  Console::recomposeFrame(function (): void {
    Console::write('stable', 2, 1);
    Console::repaintRegion(2, 1, 6, 1);
  });
  $output = ob_get_clean();

  expect($output)->toBe("\033[2;3Hstable");
});

it('repaints every locator edge when its displayed state changes', function () {
  withConsole(230, 39);
  $hadPlaySettings = ConfigStore::has(PlaySettings::class);
  $previousPlaySettings = $hadPlaySettings ? ConfigStore::get(PlaySettings::class) : null;
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 230, 'height' => 39]));

  try {
    $hud = new class(new Vector2(8, 4), MovementHeading::SOUTH) extends LocationHUDWindow {
      public function isPresentationVisible(): bool
      {
        return true;
      }
    };
    $hud->activate();

    ob_start();
    $hud->render();
    ob_end_clean();

    ob_start();
    $hud->updateDetails(new Vector2(86, 14), MovementHeading::SOUTH);
    $hud->render();
    $output = ob_get_clean();

    expect($output)->toContain("\033[36;2H╔")
      ->and($output)->toContain("\033[37;2H║ Coordinates: (86, 14) ║")
      ->and($output)->toContain("\033[38;2H║ Heading: South        ║")
      ->and($output)->toContain("\033[39;2H╚")
      ->and(substr_count($output, "\033["))->toBe(4);
  } finally {
    if ($previousPlaySettings !== null) {
      ConfigStore::put(PlaySettings::class, $previousPlaySettings);
    } else {
      ConfigStore::remove(PlaySettings::class);
    }
  }
});

/**
 * Returns the console's buffered rows, discarding terminal output.
 */
function bufferAfter(callable $writes, int $width = 20, int $height = 3): array
{
  $reflection = withConsole($width, $height);
  ob_start();
  $writes();
  ob_end_clean();

  return $reflection->getProperty('buffer')->getValue();
}

it('writes plain ascii rows exactly', function () {
  $buffer = bufferAfter(function (): void {
    Console::write('+--------+', 0, 0);
    Console::write('| hello  |', 0, 1);
  });

  expect($buffer[0])->toBe('+--------+          ')
    ->and($buffer[1])->toBe('| hello  |          ')
    ->and(strlen($buffer[0]))->toBe(20);
});

it('overwrites at an offset without disturbing the rest of the row', function () {
  $buffer = bufferAfter(function (): void {
    Console::write('| hello  |', 0, 0);
    Console::write('X', 3, 0);
  });

  expect($buffer[0])->toBe('| hXllo  |          ');
});

it('formats documented Symfony named and hexadecimal colors before buffering sprites', function () {
  $buffer = bufferAfter(function (): void {
    Console::write('<fg=bright-magenta>@</>', 0, 0);
    Console::write('<fg=#c0392b>!</>', 2, 0);
  });

  expect($buffer[0])->not->toContain('<fg=')
    ->and($buffer[0])->toContain("\033[")
    ->and(TerminalText::stripAnsi($buffer[0]))->toBe('@ !                 ');
});

it('keeps column accounting correct when a wide glyph lands in an ascii row', function () {
  // The ASCII fast path must decline here: a two-column glyph occupies one
  // string position, so byte offsets and column offsets stop agreeing.
  $buffer = bufferAfter(function (): void {
    Console::write('| hello  |', 0, 0);
    Console::write('🚶', 2, 0);
    Console::write('ok', 6, 0);
  });

  expect(TerminalText::displayWidth($buffer[0]))->toBe(20)
    ->and($buffer[0])->toContain('🚶')
    ->and($buffer[0])->toContain('ok');
});

it('makes ambiguous item pictographs occupy their reserved terminal cells', function () {
  $buffer = bufferAfter(function (): void {
    Console::write('🗡', 0, 0);
    Console::write('|', 2, 0);
  });

  // The continuation cell is internal and is omitted from the serialized
  // row. If the sword were still treated as narrow, a literal blank would
  // remain before the border and the terminal would wrap full-width rows.
  expect(TerminalText::stripAnsi($buffer[0]))->toStartWith('🗡️|')
    ->and(TerminalText::displayWidth($buffer[0]))->toBe(20);
});

it('skips re-emitting a row whose content is genuinely unchanged', function () {
  $reflection = withConsole(20, 2);

  ob_start();
  Console::write('| hello  |', 0, 0);
  $firstPass = ob_get_clean();

  ob_start();
  Console::write('| hello  |', 0, 0);
  $secondPass = ob_get_clean();

  // Safe only because every draw goes through this buffer, sprites
  // included; see the sprite-erasure test below for the case that made
  // skipping unsafe when sprites bypassed it.
  expect($firstPass)->not->toBe('')
    ->and($secondPass)->toBe('')
    ->and($reflection->getProperty('buffer')->getValue()[0])->toBe('| hello  |          ');
});

it('atomically recomposes a complete screen and clears vanished rows', function () {
  $reflection = withConsole(20, 3);

  ob_start();
  Console::write('old map', 0, 0);
  Console::write('old dialogue', 0, 1);
  ob_end_clean();

  ob_start();
  Console::recomposeFrame(function (): void {
    Console::write('new map', 0, 0);
  });
  $output = ob_get_clean();

  $buffer = $reflection->getProperty('buffer')->getValue();

  expect($buffer[0])->toBe('new map             ')
    ->and($buffer[1])->toBe(str_repeat(' ', 20))
    // Only the changed spans are emitted: " map" was already physically
    // present, and only the occupied part of the vanished row needs clearing.
    ->and($output)->toContain('new')
    ->and($output)->toContain(str_repeat(' ', strlen('old dialogue')))
    ->and(substr_count($output, "\033["))->toBe(2);
});

it('emits only final changed rows from a complete screen recomposition', function () {
  withConsole(20, 3);

  ob_start();
  Console::write('stable', 0, 0);
  Console::write('before', 0, 1);
  ob_end_clean();

  ob_start();
  Console::recomposeFrame(function (): void {
    Console::write('stable', 0, 0);
    Console::write('intermediate', 0, 1);
    Console::write('after', 0, 1);
  });
  $output = ob_get_clean();

  expect($output)->not->toContain('stable')
    ->and($output)->not->toContain('intermediate')
    ->and($output)->toContain('after')
    ->and(substr_count($output, "\033["))->toBe(1);
});

it('replaces a menu with every row of a dense styled field', function () {
  $reflection = withConsole(230, 39);

  ob_start();
  foreach (range(7, 32) as $row) {
    Console::write('| old load menu ' . str_repeat(' ', 100) . '|', 60, $row);
  }
  ob_end_clean();

  ob_start();
  Console::recomposeFrame(function (): void {
    foreach (range(5, 34) as $row) {
      Console::write(
        '<fg=green>' . str_repeat(';', 118) . '</>',
        56,
        $row,
      );
    }

    Console::write('Coordinates: (79, 12)', 2, 37);
    Console::write('HUD COMPLETE', 2, 38);
  });
  $output = ob_get_clean();
  $buffer = $reflection->getProperty('buffer')->getValue();

  expect(TerminalText::stripAnsi($buffer[20]))->not->toContain('old load menu')
    ->and(TerminalText::stripAnsi($buffer[34]))->toContain(str_repeat(';', 118))
    ->and(TerminalText::stripAnsi($buffer[38]))->toContain('HUD COMPLETE')
    ->and($output)->toContain("\033[35;57H")
    ->and($output)->toContain("\033[39;3H")
    ->and(TerminalText::stripAnsi($output))->not->toContain('old load menu');
});

it('fully repaints a new screen owner even when the canonical buffer already matches', function () {
  withConsole(20, 3);

  ob_start();
  Console::write('field', 0, 0);
  ob_end_clean();

  ob_start();
  Console::recomposeFrame(function (): void {
    Console::write('field', 0, 0);
  }, forceFullRepaint: true);
  $output = ob_get_clean();

  // Every row is delivered at full width, including blank rows. A scene
  // boundary can therefore repair physical bytes that the logical buffer
  // never observed, without flashing through a cleared screen.
  expect(substr_count($output, "\033["))->toBe(3)
    ->and($output)->toContain("\033[1;1Hfield")
    ->and($output)->toContain("\033[2;1H" . str_repeat(' ', 20))
    ->and($output)->toContain("\033[3;1H" . str_repeat(' ', 20));
});

it('restores the authoritative screen when recomposition fails', function () {
  $reflection = withConsole(20, 2);

  ob_start();
  Console::write('authoritative', 0, 0);
  ob_end_clean();
  $before = $reflection->getProperty('buffer')->getValue();

  ob_start();
  try {
    Console::recomposeFrame(function (): void {
      Console::write('partial', 0, 0);
      throw new RuntimeException('render failed');
    });
  } catch (RuntimeException $exception) {
    expect($exception->getMessage())->toBe('render failed');
  }
  $output = ob_get_clean();

  expect($output)->toBe('')
    ->and($reflection->getProperty('buffer')->getValue())->toBe($before)
    ->and($reflection->getProperty('frameDepth')->getValue())->toBe(0);
});

it('rejects complete screen recomposition inside an active frame', function () {
  withConsole(20, 2);

  Console::beginFrame();

  expect(fn() => Console::recomposeFrame(static function (): void {}))
    ->toThrow(RuntimeException::class, 'A complete screen cannot be recomposed inside an active console frame.');

  Console::endFrame();
});

it('erases a wide sprite from the row it covered', function () {
  // The regression that trailed copies of the player across the map: a
  // sprite is drawn over a map row and then erased tile by tile. Both cells
  // of the two-column glyph must come back, and the erase must reach the
  // terminal.
  $reflection = withConsole(24, 2);

  ob_start();
  Console::write(str_repeat('.', 24), 0, 0);
  ob_end_clean();

  ob_start();
  Console::write(TerminalText::stabilize('🚶'), 4, 0);
  $withSprite = $reflection->getProperty('buffer')->getValue()[0];
  Console::write('.', 4, 0);
  Console::write('.', 5, 0);
  $eraseOutput = ob_get_clean();

  $afterErase = $reflection->getProperty('buffer')->getValue()[0];

  expect($withSprite)->toContain('🚶')
    ->and($afterErase)->toBe(str_repeat('.', 24))
    ->and(TerminalText::displayWidth($afterErase))->toBe(24)
    ->and($eraseOutput)->not->toBe('');
});

it('batches a frame into a single terminal write', function () {
  withConsole(20, 3);

  ob_start();
  Console::beginFrame();
  Console::write('row one', 0, 0);
  Console::write('row two', 0, 1);
  Console::write('row three', 0, 2);
  Console::endFrame();
  $output = ob_get_clean();

  // All three rows arrive in one payload, each preceded by its own cursor
  // positioning sequence.
  expect($output)->toContain('row one')
    ->and($output)->toContain('row two')
    ->and($output)->toContain('row three')
    ->and(substr_count($output, "\033["))->toBe(3);
});

it('emits sparse window interiors without retransmitting unchanged blanks', function () {
  withConsole(230, 39);
  $windows = [
    new Window(position: new Vector2(60, 2), width: 110, height: 3),
    new Window(position: new Vector2(60, 5), width: 80, height: 3),
    new Window(position: new Vector2(140, 5), width: 30, height: 3),
    new Window(position: new Vector2(60, 8), width: 55, height: 29),
    new Window(position: new Vector2(115, 8), width: 55, height: 29),
  ];

  ob_start();
  Console::beginFrame();

  foreach ($windows as $window) {
    $window->render();
  }

  Console::endFrame();
  $output = ob_get_clean();

  // This is the shop's complete empty-panel geometry at 230x39. Its first
  // frame used to exceed the terminal writer's 4 KiB boundary because every
  // blank cell between the vertical borders was marked dirty. The bottom
  // borders must still be present, but the frame should now remain compact.
  expect($output)->toContain("\033[37;61H")
    ->and(TerminalText::stripAnsi($output))->toContain(str_repeat('═', 108))
    ->and(strlen($output))->toBeLessThan(4096);
});

it('nests batched frames and flushes once at the outermost close', function () {
  withConsole(20, 2);

  ob_start();
  Console::beginFrame();
  Console::write('outer', 0, 0);
  Console::beginFrame();
  Console::write('inner', 0, 1);
  Console::endFrame();
  $beforeOuterClose = ob_get_contents();
  Console::endFrame();
  $afterOuterClose = ob_get_clean();

  expect($beforeOuterClose)->toBe('')
    ->and($afterOuterClose)->toContain('outer')
    ->and($afterOuterClose)->toContain('inner');
});

it('coalesces repeated row paints to the final frame composition', function () {
  withConsole(20, 2);

  ob_start();
  Console::beginFrame();
  Console::write('intermediate', 0, 0);
  Console::write('final', 0, 0);
  Console::write('second row', 0, 1);
  Console::endFrame();
  $output = ob_get_clean();

  expect($output)->not->toContain('intermediate')
    ->and($output)->toContain('final')
    ->and($output)->toContain('second row')
    ->and(substr_count($output, "\033[1;1H"))->toBe(1)
    ->and(substr_count($output, "\033[2;1H"))->toBe(1);
});

it('tracks window borders and content in the canonical console buffer', function () {
  $reflection = withConsole(24, 8);
  $window = new Window('Info', '', new Vector2(2, 1), 12, 3);
  $window->setContent(['tracked']);

  ob_start();
  $window->render();
  ob_end_clean();

  $buffer = $reflection->getProperty('buffer')->getValue();
  $renderedRows = array_map(TerminalText::stripAnsi(...), $buffer);

  expect(substr($renderedRows[1], 2, 12))->toContain('Info')
    ->and(substr($renderedRows[2], 2, 12))->toContain('tracked')
    ->and(substr($renderedRows[3], 2, 12))->not->toBe(str_repeat(' ', 12));

  ob_start();
  $window->erase();
  ob_end_clean();

  $erasedRows = $reflection->getProperty('buffer')->getValue();

  expect(substr(TerminalText::stripAnsi($erasedRows[1]), 2, 12))->toBe(str_repeat(' ', 12))
    ->and(substr(TerminalText::stripAnsi($erasedRows[2]), 2, 12))->toBe(str_repeat(' ', 12))
    ->and(substr(TerminalText::stripAnsi($erasedRows[3]), 2, 12))->toBe(str_repeat(' ', 12));
});

it('flushes only an overlay span instead of retransmitting its styled field rows', function () {
  withConsole(230, 39);
  $styledFieldRow = '<fg=green>OUTSIDE ' . str_repeat(';', 210) . '</>';

  ob_start();
  foreach (range(17, 21) as $row) {
    Console::write($styledFieldRow, 0, $row);
  }
  ob_end_clean();

  $window = new Window(
    position: new Vector2(90, 17),
    width: 50,
    height: 5,
  );
  $window->setContent([
    'Inspect the ROUTE CHECK enclosure west of the',
    'Field Post before checking in.',
    'OK',
  ]);

  ob_start();
  $window->render();
  $output = ob_get_clean();
  $visibleOutput = TerminalText::stripAnsi($output);

  expect($visibleOutput)->not->toContain('OUTSIDE')
    ->and($visibleOutput)->toContain('Inspect the ROUTE CHECK enclosure west of the')
    ->and($visibleOutput)->toContain('Field Post before checking in.')
    ->and(substr_count($output, "\033["))->toBe(5);
});
