<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;

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

it('re-emits a row even when its buffered content is unchanged', function () {
  $reflection = withConsole(20, 2);

  ob_start();
  Console::write('| hello  |', 0, 0);
  $firstPass = ob_get_clean();

  ob_start();
  Console::write('| hello  |', 0, 0);
  $secondPass = ob_get_clean();

  // Skipping "unchanged" rows looks like a free optimisation and is not:
  // sprites are drawn straight to the terminal and never enter this buffer,
  // so a row that matches the buffer may still be covering a sprite that
  // has to be painted over.
  expect($firstPass)->not->toBe('')
    ->and($secondPass)->not->toBe('')
    ->and($reflection->getProperty('buffer')->getValue()[0])->toBe('| hello  |          ');
});

it('erases a sprite that was drawn outside the buffer', function () {
  // Reproduces the real regression: the map tile is written, a sprite is
  // drawn directly over it (as Camera::renderOnScreen does), and then the
  // tile is written again to erase the sprite. That final write must reach
  // the terminal even though the buffer never changed.
  withConsole(20, 2);

  ob_start();
  Console::write('....................', 0, 0);   // map row
  echo "\033[1;5H@";                               // sprite drawn directly
  Console::write('....................', 0, 0);   // erase pass
  $output = ob_get_clean();

  $eraseWrite = substr($output, strpos($output, '@') + 1);

  expect($eraseWrite)->toContain('....................');
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
