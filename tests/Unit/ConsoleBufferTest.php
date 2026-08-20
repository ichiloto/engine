<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;

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
  ] as [$name, $value]) {
    $reflection->getProperty($name)->setValue(null, $value);
  }

  return $reflection;
}

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
    ->and($output)->toContain('new map')
    ->and($output)->toContain(str_repeat(' ', 20))
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
