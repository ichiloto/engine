<?php

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;

it('measures colored and emoji text without counting ansi sequences', function () {
  $text = Color::apply('Potion', Color::LIGHT_GREEN) . ' 🧪';

  expect(TerminalText::displayWidth($text))->toBe(9)
    ->and(TerminalText::symbolCount($text))->toBe(8)
    ->and(TerminalText::stripAnsi($text))->toBe('Potion 🧪');
});

it('pads ansi colored text using visible width', function () {
  $text = Color::apply('Rare', Color::YELLOW);
  $padded = TerminalText::padRight($text, 8);

  expect(TerminalText::displayWidth($padded))->toBe(8)
    ->and(TerminalText::stripAnsi($padded))->toBe('Rare    ');
});

it('slices visible symbols without breaking ansi styling', function () {
  $text = Color::apply('o', Color::LIGHT_GREEN) . 'x?';
  $slice = TerminalText::sliceSymbols($text, 0, 2);

  expect(TerminalText::stripAnsi($slice))->toBe('ox')
    ->and(TerminalText::symbolCount($slice))->toBe(2)
    ->and($slice)->toContain("\033[");
});

it('treats symfony formatter tags as zero-width styling', function () {
  $text = '<info>;</info><fg=blue>~</>';
  $symbols = TerminalText::visibleSymbols($text);

  expect(TerminalText::stripAnsi($text))->toBe(';~')
    ->and(TerminalText::symbolCount($text))->toBe(2)
    ->and(TerminalText::displayWidth($text))->toBe(2)
    ->and($symbols[0] ?? '')->toContain("\033[")
    ->and($symbols[1] ?? '')->toContain("\033[");
});
