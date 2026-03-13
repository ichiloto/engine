<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;

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
