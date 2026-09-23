<?php

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Enumerations\Color;

it('derives contrasting terminal flash backgrounds from the canonical palette', function () {
  $dark = SgrColorParser::parse(Color::BLUE->getContrastingBackgroundSequence() . 'X');
  $bright = SgrColorParser::parse(Color::YELLOW->getContrastingBackgroundSequence() . 'X');
  expect($dark['background']?->toArray())->toBe(['kind' => 'ansi16', 'index' => 4])
    ->and($dark['foreground']?->toArray())->toBe(['kind' => 'ansi16', 'index' => 15])
    ->and($bright['background']?->toArray())->toBe(['kind' => 'ansi16', 'index' => 11])
    ->and($bright['foreground']?->toArray())->toBe(['kind' => 'ansi16', 'index' => 0]);
});

it('measures colored and emoji text without counting ansi sequences', function () {
  $text = Color::apply('Potion', Color::LIGHT_GREEN) . ' 🧪';

  expect(TerminalText::displayWidth($text))->toBe(9)
    ->and(TerminalText::symbolCount($text))->toBe(8)
    ->and(TerminalText::stripAnsi($text))->toBe('Potion 🧪');
});

it('measures already separated styled graphemes without changing cell widths', function (string $text) {
  foreach (TerminalText::visibleSymbols($text) as $symbol) {
    expect(TerminalText::getSymbolWidth($symbol))->toBe(TerminalText::displayWidth($symbol));
  }
})->with(['ASCII', "\e[31mA界\e[0m", "e\u{0301}", '⚔️🗡🚶',
  "\e[38;2;12;23;34;48;5;21m界\e[0m", '🏃🏽‍➡️']);

it('pads ansi colored text using visible width', function () {
  $text = Color::apply('Rare', Color::YELLOW);
  $padded = TerminalText::padRight($text, 8);

  expect(TerminalText::displayWidth($padded))->toBe(8)
    ->and(TerminalText::stripAnsi($padded))->toBe('Rare    ');
});

it('fits styled graphemes without changing truncation or alignment', function (string $text) {
  foreach ([-2, 0, 1, 2, 3, 4, 7, 24] as $width) {
    $prefix = TerminalText::truncateToWidth($text, $width);
    $padding = max(0, $width - TerminalText::displayWidth($prefix));
    $centerLeft = intdiv($padding, 2);

    expect(TerminalText::padRight($text, $width))->toBe($prefix . str_repeat(' ', $padding))
      ->and(TerminalText::padLeft($text, $width))->toBe(str_repeat(' ', $padding) . $prefix)
      ->and(TerminalText::padCenter($text, $width))
      ->toBe(str_repeat(' ', $centerLeft) . $prefix . str_repeat(' ', $padding - $centerLeft))
      ->and(TerminalText::fit($text, $width, 'unknown'))->toBe(TerminalText::padRight($text, $width));
  }
})->with([
  '', 'ASCII', "\e[31;44mA界\e[39mB\e[0m", '<fg=blue>界</> X',
  "e\u{0301} ⚔️🗡", '🏃🏽‍➡️ end', "\e[31m\e[999m\e[39mX\e[0m",
  "\e[38;2;0;0;128m界\e[0mA",
  "🇺\e[0m🇸", "👩\e[0m\u{200d}💻",
]);

it('remeasures graphemes joined by removal of formatting controls', function () {
  $splitFlag = "🇺\e[0m🇸";
  expect(TerminalText::padRight($splitFlag, 4))->toBe('🇺🇸  ')
    ->and(TerminalText::padLeft($splitFlag, 4))->toBe('  🇺🇸')
    ->and(TerminalText::padCenter($splitFlag, 4))->toBe(' 🇺🇸 ');
});

it('keeps ascii width measurement equivalent to the styled grapheme path', function (string $text) {
  $width = array_sum(array_map(TerminalText::getSymbolWidth(...), TerminalText::visibleSymbols($text)));
  expect(TerminalText::displayWidth($text))->toBe($width);
})->with([
  '', 'plain ASCII', '<fg=red>styled</> text', "\e[31mred\e[0mplain", "\e[31m\e[999mX\e[0m",
  "\e[38;2;1;;3mX", "a\nb\tc", "a\r\nb", '\\<info>literal</info>',
  "\e[38;2;0;0;128mblue\e[0m", "\e[2JX", "\e[31m\e[0m",
]);

it('does not apply an open formatter tag twice when measuring a control character', function (string $control) {
  $formatter = new ReflectionProperty(TerminalText::class, 'formatter');
  $previous = $formatter->getValue();
  $formatter->setValue(null, null);

  try {
    expect(TerminalText::displayWidth('<info>line' . $control))->toBe(5)
      ->and(TerminalText::formatStyles('</>X<comment>Y</comment>'))->toBe("X\e[33mY\e[39m");
  } finally {
    $formatter->setValue(null, $previous);
  }
})->with(["\n", "\t"]);

it('pads the remainder when the next complete glyph will not fit', function () {
  expect(TerminalText::padRight('A界B', 2))->toBe('A ')
    ->and(TerminalText::padLeft('A界B', 2))->toBe(' A')
    ->and(TerminalText::padCenter('界', 5))->toBe(' 界  ')
    ->and(TerminalText::truncateToWidth('界A', 1))->toBe('');
});

it('wraps styled text by visible width without losing the highlighted key', function () {
  $text = 'Press ' . Color::apply('T', Color::YELLOW) . ' to watch.';
  $lines = TerminalText::wrapToWidth($text, 10);

  expect(array_map([TerminalText::class, 'stripAnsi'], $lines))->toBe(['Press T', 'to watch.'])
    ->and(SgrColorParser::parse(TerminalText::visibleSymbols($lines[0])[6]))
    ->toEqual(SgrColorParser::parse(Color::YELLOW->value . 'T'));
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

it('keeps cell styles bounded across repeated selective colour resets', function () {
  $text = str_repeat("\e[92m;\e[39m\e[90m.\e[39m ", 100);
  $symbols = TerminalText::visibleSymbols($text);
  expect(count($symbols))->toBe(300)
    ->and(max(array_map(strlen(...), $symbols)))->toBe(10)
    ->and($symbols[299])->toBe(' ')
    ->and(TerminalText::visibleSymbols(implode('', $symbols)))->toBe($symbols);
});

it('preserves unrelated terminal attributes when resetting one group', function (string $input, string $prefix) {
  expect(TerminalText::visibleSymbols($input . 'X')[0])->toBe($prefix . 'X' . ($prefix === '' ? '' : "\e[0m"));
})->with([
  'foreground reset keeps background and underline' => ["\e[31;44;4m\e[39m", "\e[44m\e[4m"],
  'background reset keeps bold foreground' => ["\e[1;31;44m\e[49m", "\e[1m\e[31m"],
  'normal intensity clears bold and faint only' => ["\e[1;2;3;32m\e[22m", "\e[3m\e[32m"],
  'double underline reset' => ["\e[21;7m\e[24m", "\e[7m"],
  'compound full reset then foreground' => ["\e[1;44m\e[0;31m", "\e[31m"],
  'empty parameter resets then foreground' => ["\e[1;44m\e[;31m", "\e[31m"],
  'rgb zeros are components not resets' => ["\e[4;38;2;0;0;128m", "\e[4m\e[38;2;0;0;128m"],
  'indexed zero is not a reset' => ["\e[4;38;5;0m", "\e[4m\e[38;5;0m"],
  'all selective resets return to plain' => ["\e[1;3;4;5;7;8;9;31;44m\e[22;23;24;25;27;28;29;39;49m", ''],
]);

it('preserves unknown and malformed controls rather than silently losing terminal semantics', function () {
  expect(TerminalText::visibleSymbols("\e[31m\e[999m\e[39mX")[0])->toBe("\e[31m\e[999m\e[39mX\e[0m")
    ->and(TerminalText::visibleSymbols("\e[38;2;1;;3mX")[0])->toBe("\e[38;2;1;;3mX\e[0m")
    ->and(TerminalText::visibleSymbols("\e[999m\e[0;32mX")[0])->toBe("\e[32mX\e[0m");
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
