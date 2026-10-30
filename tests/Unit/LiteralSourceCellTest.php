<?php

use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\IO\Console\TerminalText;
use Symfony\Component\Console\Output\AnsiColorMode;
use Symfony\Component\Console\Terminal;

it('persists exact authored colours without truecolor host support and restores the display mode', function (AnsiColorMode $mode) {
  $originalMode = Terminal::getColorMode();
  $originalColorTerm = getenv('COLORTERM');
  putenv('COLORTERM');
  Terminal::setColorMode($mode);
  try {
    $source = TerminalText::composeSourceCell('\\', '<fg=#af875f;bg=#123456;options=bold>', '</>');
    expect($source)->toContain("\e[38;2;175;135;95;48;2;18;52;86;1m")
      ->and(Terminal::getColorMode())->toBe($mode)
      ->and(TerminalText::parseSourceCells($source)[0]['symbol'])->toBe('\\');
    expect(fn() => TerminalText::composeSourceCell('<', '<invalid-style>', '</>'))->toThrow(InvalidArgumentException::class);
    expect(Terminal::getColorMode())->toBe($mode);
  } finally {
    Terminal::setColorMode($originalMode);
    $originalColorTerm === false ? putenv('COLORTERM') : putenv('COLORTERM=' . $originalColorTerm);
  }
})->with([AnsiColorMode::Ansi4, AnsiColorMode::Ansi8, AnsiColorMode::Ansi24]);

it('resolves source style boundaries directly without adding a glyph or changing the display mode', function () {
  $mode = Terminal::getColorMode();
  try {
    Terminal::setColorMode(AnsiColorMode::Ansi4);
    $style = TerminalText::resolveSourceStyle('<fg=#af875f;bg=#123456;options=bold>', '</>');
    expect($style['prefix'])->toBe("\e[38;2;175;135;95;48;2;18;52;86;1m")
      ->and($style['suffix'])->toBe("\e[39;49;22m")
      ->and(TerminalText::resolveSourceStyle('', ''))->toBe(['prefix' => '', 'suffix' => ''])
      ->and(Terminal::getColorMode())->toBe(AnsiColorMode::Ansi4);
    expect(fn() => TerminalText::resolveSourceStyle('<fg=red>literal', '</>'))->toThrow(InvalidArgumentException::class);
    expect(Terminal::getColorMode())->toBe(AnsiColorMode::Ansi4);
  } finally { Terminal::setColorMode($mode); }
});

it('keeps plain source fast paths exact for ASCII and combined Unicode graphemes', function (string $source, array $glyphs) {
  expect(TerminalText::parseSourceCells($source))->toBe(array_map(
    static fn(string $glyph): array => ['symbol' => $glyph, 'prefix' => '', 'suffix' => ''], $glyphs));
})->with([
  ['', []], [' .#> 0', [' ', '.', '#', '>', ' ', '0']],
  ["e\u{0301}🇿🇲👩‍🔬", ["e\u{0301}", '🇿🇲', '👩‍🔬']],
]);

it('constructs styled literal delimiters without allowing a backslash to escape the closing style', function (string $glyph) {
  $source = TerminalText::composeSourceCell($glyph, '<fg=#af875f>', '</>');
  expect(TerminalText::stripAnsi($source))->toBe($glyph)
    ->and(TerminalText::symbolCount($source))->toBe(1)
    ->and(TerminalText::displayWidth($source))->toBe(1)
    ->and($source)->not->toContain("\0");
  $parts = TerminalText::parseSourceCells($source);
  expect($parts[0]['symbol'])->toBe($glyph)
    ->and(TerminalText::getSourceSymbols($source))->toBe([$source]);
  $text = $source . TerminalText::composeSourceCell('x', '<fg=blue>', '</>');
  expect(TerminalText::stripAnsi($text))->toBe($glyph . 'x')
    ->and(TerminalText::symbolCount($text))->toBe(2);
})->with(['\\', '<', '>']);

it('keeps separately authored literal cells from forming a formatter tag when placed side by side', function () {
  $glyphs = ['<', 'i', 'n', 'f', 'o', '>', '\\'];
  $row = implode('', array_map(TerminalText::composeSourceCell(...), $glyphs));
  expect(TerminalText::stripAnsi($row))->toBe('<info>\\')
    ->and(array_column(TerminalText::parseSourceCells($row), 'symbol'))->toBe($glyphs);
  $split = TerminalText::getSourceSymbols($row);
  expect(TerminalText::stripAnsi(implode('', $split)))->toBe('<info>\\')
    ->and(array_map(TerminalText::stripAnsi(...), $split))->toBe($glyphs);
  $php = MapGridSource::buildSource($row, 'LITERAL_ROW');
  expect(MapGridSource::parseSource($php, 'synthetic'))->toBe($row)
    ->and(new MapLayer('literal', 0, false, 'synthetic', $row)->getRowGlyphs(0))->toBe($glyphs);
});

it('retains regular authored style spelling and grapheme bytes without sharing formatter state', function () {
  $property = new ReflectionProperty(TerminalText::class, 'formatter');
  $previous = $property->getValue();
  $property->setValue(null, null);
  try {
    TerminalText::formatStyles('<info>');
    $literal = TerminalText::composeSourceCell('\\', '<fg=red><options=bold>', '</></>');
    expect(TerminalText::stripAnsi($literal))->toBe('\\')
      ->and(TerminalText::formatStyles('x</><comment>y</comment>'))->toContain("\e[32mx")
      ->and(TerminalText::composeSourceCell("e\u{0301}", '<fg=#AbCdEf>', '</fg=#abcdef>'))
        ->toBe("<fg=#AbCdEf>e\u{0301}</fg=#abcdef>");
  } finally { $property->setValue(null, $previous); }
});

it('preserves the existing formatter escape meaning for authored escaped tags', function () {
  expect(TerminalText::stripAnsi('<fg=red>\\<info\\>literal\\</info\\></>'))->toBe('<info>literal</info>');
  expect(fn() => TerminalText::composeSourceCell('ab'))->toThrow(InvalidArgumentException::class)
    ->and(fn() => TerminalText::composeSourceCell('\\', '<not-a-style>', '</>'))->toThrow(InvalidArgumentException::class);
});
