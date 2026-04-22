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
  $bufferProperty->setValue(null, array_fill(0, $height, str_repeat(' ', $width)));

  $outputProperty = $console->getProperty('output');
  $outputProperty->setValue(null, null);
}
