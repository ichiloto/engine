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

/* Width-unstable glyph stabilization */

it('distinguishes plain text pictographs from explicit emoji presentation', function () {
  expect(TerminalText::displayWidth('⚔️'))->toBe(2)
    ->and(TerminalText::displayWidth('⚔'))->toBe(1)
    ->and(TerminalText::displayWidth('➡️'))->toBe(2)
    ->and(TerminalText::displayWidth('➡'))->toBe(1);
});

it('stabilizes astral pictograph widths and respects explicit text presentation', function () {
  expect(TerminalText::displayWidth('🗡️'))->toBe(2)
    ->and(TerminalText::displayWidth('🗡'))->toBe(2)
    ->and(TerminalText::displayWidth("🗡\u{FE0E}"))->toBe(1)
    ->and(TerminalText::displayWidth('🚶'))->toBe(2);
});

it('preserves explicit variation selectors', function () {
  expect(TerminalText::stabilizeSymbol('⚔️'))->toBe('⚔️')
    ->and(TerminalText::stabilizeSymbol('🗡️'))->toBe('🗡️')
    ->and(TerminalText::stabilizeSymbol("🗡\u{FE0E}"))->toBe("🗡\u{FE0E}")
    ->and(TerminalText::stabilizeSymbol('🧪️'))->toBe('🧪️')
    ->and(TerminalText::stabilizeSymbol('A'))->toBe('A');
});

it('makes ambiguous astral pictographs explicitly two cells', function () {
  expect(TerminalText::stabilizeSymbol('🗡'))->toBe('🗡️')
    ->and(TerminalText::stabilize('Weapon: 🗡Wooden Sword'))->toBe('Weapon: 🗡️Wooden Sword')
    ->and(TerminalText::stabilize('plain ⚔ ♥ symbols'))->toBe('plain ⚔ ♥ symbols');
});

it('reduces zwj sequences and skin tones to their base glyph', function () {
  expect(TerminalText::stabilizeSymbol('🏃🏽‍➡️'))->toBe('🏃')
    ->and(TerminalText::stabilizeSymbol('🏃🏽'))->toBe('🏃')
    ->and(TerminalText::displayWidth('🏃🏽‍➡️'))->toBe(2);
});

it('stabilizes whole strings while preserving stable content and ansi styling', function () {
  $styled = Color::apply('⚔️', Color::LIGHT_GREEN);

  expect(TerminalText::stabilize('⚔️ Radiant 🗡️ Slash'))->toBe('⚔️ Radiant 🗡️ Slash')
    ->and(TerminalText::stabilize('plain ascii'))->toBe('plain ascii')
    ->and(TerminalText::stripAnsi(TerminalText::stabilize($styled)))->toBe('⚔️')
    ->and(TerminalText::stabilize($styled))->toContain("\033[");
});

it('keeps padded columns aligned around unstable glyphs', function () {
  expect(TerminalText::displayWidth(TerminalText::padRight('⚔️ Radiant Slash', 24)))->toBe(24)
    ->and(TerminalText::displayWidth(TerminalText::padRight('🗡️ Shadowstep (2 MP)', 58)))->toBe(58)
    ->and(TerminalText::displayWidth(TerminalText::padRight('🗡 Wooden Sword', 24)))->toBe(24)
    ->and(TerminalText::stabilize(TerminalText::padRight('🗡️ Shadowstep (2 MP)', 58)))->toContain("️")
    ->and(TerminalText::displayWidth(TerminalText::padRight('> 🗡️ Shadowstep (2 MP)', 58) . '║'))->toBe(59)
    ->and(TerminalText::displayWidth(TerminalText::padRight('🏃🏽‍➡️ Sprint', 24)))->toBe(24);
});
