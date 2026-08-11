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

  foreach ([['width', $width], ['height', $height], ['buffer', []]] as [$name, $value]) {
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
