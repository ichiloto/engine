<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\AnimationCell;
use Ichiloto\Engine\Field\PlayerSpriteSet;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Text\TextViewport;

it('keeps adjacent Unicode graphemes independent without changing their source bytes', function (string $text, array $expected) {
  expect(TerminalText::splitGraphemes($text))->toBe($expected)
    ->and(implode('', TerminalText::splitGraphemes($text)))->toBe($text);
})->with([
  'adjacent terrain pictographs' => ['♣♣a♥♥☃☃', ['♣', '♣', 'a', '♥', '♥', '☃', '☃']],
  'adjacent emoji' => ['😀😀', ['😀', '😀']],
  'combining marks' => ["e\u{0301}e\u{0301}", ["e\u{0301}", "e\u{0301}"]],
  'ZWJ sequences' => ['👩‍💻👩‍💻', ['👩‍💻', '👩‍💻']],
  'flags' => ['🇿🇲🇺🇸', ['🇿🇲', '🇺🇸']],
  'carriage-return newline' => ["\r\nA", ["\r\n", 'A']],
  'empty' => ['', []],
]);

it('keeps styled authored and displayed cells aligned with raw grapheme boundaries', function () {
  $source = '<fg=green>♣♣</>..';
  expect(TerminalText::symbolCount($source))->toBe(4)
    ->and(TerminalText::getSourceSymbols($source))->toBe(['<fg=green>♣</>', '<fg=green>♣</>', '.', '.'])
    ->and(array_map(TerminalText::stripAnsi(...), TerminalText::visibleSymbols("\e[31m♥♥\e[0m☃☃")))
    ->toBe(['♥', '♥', '☃', '☃']);
  expect(fn() => TerminalText::composeSourceCell('♣♣'))->toThrow(InvalidArgumentException::class);
  expect(TerminalText::composeSourceCell('👩‍💻'))->toBe('👩‍💻');
});

it('shares complete cell boundaries across animation, sprite sanitization and viewport source offsets', function () {
  expect(new AnimationCell('♣♣', 0, 0)->symbol)->toBe('♣')
    ->and(TextViewport::measure('😀😀'))->toBe(4)
    ->and(TextViewport::wrap('😀😀', 2))->toBe([[0, '😀'], [4, '😀']]);
  $runner = '🏃🏽‍➡️';
  expect(PlayerSpriteSet::sanitizeSpriteRow($runner . $runner))
    ->toBe(str_repeat(PlayerSpriteSet::sanitizeSpriteRow($runner), 2))
    ->and(TerminalText::stabilize($runner . $runner))->toBe(str_repeat(TerminalText::stabilize($runner), 2));
});

it('preserves existing malformed-UTF8 outcomes in text and drawable normalization', function () {
  $invalid = "\xff";
  expect(TerminalText::splitGraphemes($invalid))->toBeFalse()
    ->and(TerminalText::visibleSymbols($invalid))->toBe([])
    ->and(PlayerSpriteSet::sanitizeSpriteRow($invalid))->toBe($invalid)
    ->and(new AnimationCell($invalid, 0, 0)->symbol)->toBe(' ');
});
