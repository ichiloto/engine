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
  $console = new ReflectionClass(Console::class);

  $width = $console->getProperty('width');
  $width->setAccessible(true);
  $width->setValue(20);

  $height = $console->getProperty('height');
  $height->setAccessible(true);
  $height->setValue(5);

  $buffer = $console->getProperty('buffer');
  $buffer->setAccessible(true);
  $buffer->setValue(array_fill(0, 5, str_repeat(' ', 20)));

  $output = $console->getProperty('output');
  $output->setAccessible(true);
  $output->setValue(null);

  ob_start();
  Console::write('Z', 10.8, 2.9);
  ob_end_clean();

  $bufferRows = Console::getBuffer();
  $symbols = TerminalText::visibleSymbols($bufferRows[2]);

  expect(TerminalText::stripAnsi($symbols[10] ?? ''))->toBe('Z');
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
