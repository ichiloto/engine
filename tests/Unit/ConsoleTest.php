<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;

class ConsoleTestProxy extends Console
{
  public static function parseSttySize(string $output): ?array
  {
    return parent::parseSttySizeOutput($output);
  }

  public static function normalizeSize(mixed $width, mixed $height): ?array
  {
    return parent::normalizeAvailableSize($width, $height);
  }
}

it('floors float coordinates when writing to the console buffer', function () {
  setConsoleDimensionsForTest(20, 5);

  ob_start();
  Console::write('Z', 10.8, 2.9);
  ob_end_clean();

  $bufferRows = Console::getBuffer();
  $symbols = TerminalText::visibleSymbols($bufferRows[2]);

  expect(TerminalText::stripAnsi($symbols[10] ?? ''))->toBe('Z');
});

it('clips text past the left and right edges instead of drawing it at the edge', function () {
  setConsoleDimensionsForTest(8, 2);

  ob_start();
  Console::write('i', -3, 0);
  Console::write('/', 8, 0);
  Console::write('abcd', -2, 1);
  Console::write('xyz', 6, 1);
  ob_end_clean();

  $rows = array_map(static fn(string $row): string => TerminalText::stripAnsi($row), Console::getBuffer());
  expect($rows[0])->toBe('        ')
    ->and($rows[1])->toBe('cd    xy');
});

it('drops a wide glyph the left edge cuts, keeping the text after it', function () {
  setConsoleDimensionsForTest(6, 1);

  ob_start();
  Console::write('😀ab', -1, 0);
  ob_end_clean();

  expect(TerminalText::stripAnsi(Console::getBuffer()[0]))->toBe(' ab   ');
});

it('keeps emoji writes aligned to terminal cell width', function () {
  setConsoleDimensionsForTest(8, 3);

  ob_start();
  Console::write('😀', 1, 0);
  Console::write('Z', 3, 0);
  ob_end_clean();

  $row = Console::getBuffer()[0];

  expect(TerminalText::displayWidth($row))->toBe(8)
    ->and(TerminalText::stripAnsi($row))->toBe(" 😀Z    ");
});

it('keeps an explicitly presented emoji and trailing border in their cells', function () {
  setConsoleDimensionsForTest(10, 3);

  ob_start();
  Console::write('🗡️', 1, 0);
  Console::write('║', 3, 0);
  ob_end_clean();

  $row = Console::getBuffer()[0];

  expect(TerminalText::displayWidth($row))->toBe(10)
    ->and(TerminalText::stripAnsi($row))->toBe(" 🗡️║      ")
    ->and(Console::charAt(1, 0))->toBe('🗡️')
    ->and(Console::charAt(2, 0))->toBe('🗡️')
    ->and(Console::charAt(3, 0))->toBe('║');
});

it('clears a full wide glyph when overwriting its trailing cell', function () {
  setConsoleDimensionsForTest(6, 3);

  ob_start();
  Console::write('😀', 1, 0);
  Console::write('A', 2, 0);
  ob_end_clean();

  $row = Console::getBuffer()[0];

  expect(TerminalText::displayWidth($row))->toBe(6)
    ->and(TerminalText::stripAnsi($row))->toBe('  A   ');
});

it('reads terminal cells correctly after wide glyph writes', function () {
  setConsoleDimensionsForTest(8, 3);

  ob_start();
  Console::write('😀', 1, 0);
  Console::write('Z', 3, 0);
  ob_end_clean();

  expect(Console::charAt(1, 0))->toBe('😀')
    ->and(Console::charAt(2, 0))->toBe('😀')
    ->and(Console::charAt(3, 0))->toBe('Z');
});

it('parses stty terminal size output into width and height', function () {
  expect(ConsoleTestProxy::parseSttySize("36 170\n"))->toBe([
    'width' => 170,
    'height' => 36,
  ]);
});

it('rejects invalid terminal size values during normalization', function () {
  expect(ConsoleTestProxy::normalizeSize('abc', 36))->toBeNull()
    ->and(ConsoleTestProxy::normalizeSize(0, 0))->toBe([
      'width' => 1,
      'height' => 1,
    ]);
});

/**
 * Seeds the Console singleton with deterministic dimensions and an empty buffer.
 *
 * @param int $width The test width.
 * @param int $height The test height.
 * @return void
 */
function setConsoleDimensionsForTest(int $width, int $height): void
{
  $console = new ReflectionClass(Console::class);

  $widthProperty = $console->getProperty('width');
  $widthProperty->setValue(null, $width);

  $heightProperty = $console->getProperty('height');
  $heightProperty->setValue(null, $height);

  $bufferProperty = $console->getProperty('buffer');
  $bufferProperty->setValue(null, array_fill(0, $height, array_fill(0, $width, ' ')));

  $outputProperty = $console->getProperty('output');
  $outputProperty->setValue(null, null);
}
