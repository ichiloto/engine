<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Text\TerminalMenuRow;

it('composes native-width icons labels and a fixed right column using terminal cells', function (string $icon, int $iconCells) {
  $label = TerminalMenuRow::composeIconLabel($icon, 'Fixture');
  $row = TerminalMenuRow::compose($label, '123 G', 30, prefix: ' > ', valueWidth: 8);
  $cells = NormalizedRow::fromText($row)->cells;

  expect(TerminalText::stripAnsi($label))->toBe(TerminalText::stripAnsi(TerminalText::stabilize($icon)) . ' Fixture')
    ->and(TerminalText::displayWidth($label))->toBe($iconCells + 8)
    ->and(TerminalText::displayWidth($row))->toBe(30)
    ->and(count($cells))->toBe(30)
    ->and(TerminalText::stripAnsi(implode('', array_slice($cells, 25))))->toBe('123 G')
    ->and(TerminalText::stripAnsi($cells[3 + $iconCells]))->toBe(' ')
    ->and(TerminalText::stripAnsi($cells[4 + $iconCells]))->toBe('F');
})->with([
  'emoji selector' => ["\u{2694}\u{FE0F}", 2],
  'astral emoji selector' => ["\u{1F5E1}\u{FE0F}", 2],
  'ambiguous astral pictograph' => ["\u{1F5E1}", 2],
  'text selector' => ["\u{1F5E1}\u{FE0E}", 1],
  'narrow symbol' => ["\u{269A}", 1],
  'ASCII multi-cell' => ['o~', 2],
  'long ASCII icon' => ['[=>]', 4],
  'combining grapheme' => ["e\u{0301}", 1],
  'ANSI wide icon' => ["\e[31;4m\u{754C}\e[0m", 2],
  'formatter icon' => ['<fg=blue>o~</>', 2],
]);

it('preserves icon styling and stabilizes the emitted text before fitting columns', function () {
  $label = TerminalMenuRow::composeIconLabel("\e[31;4m\u{1F5E1}\e[0m", 'Fixture');
  $row = TerminalMenuRow::compose($label, '<fg=blue>5 G</>', 20, prefix: ' > ');
  $cells = NormalizedRow::fromText($row)->cells;

  expect(TerminalText::stripAnsi($label))->toBe("\u{1F5E1}\u{FE0F} Fixture")
    ->and($row)->toBe(TerminalText::stabilize($row))
    ->and(SgrColorParser::parse($cells[3])['foreground']?->toArray())->toBe(['kind' => 'ansi16', 'index' => 1])
    ->and(SgrColorParser::parse($cells[17])['foreground']?->toArray())->toBe(['kind' => 'ansi16', 'index' => 4]);
});

it('reserves the right column before clipping a long icon label', function () {
  $label = TerminalMenuRow::composeIconLabel("\u{2694}\u{FE0F}", str_repeat('Long ', 20));
  $row = TerminalMenuRow::compose($label, '7 G', 14, prefix: ' > ', valueWidth: 5);
  expect(TerminalText::displayWidth($row))->toBe(14)
    ->and(TerminalText::stripAnsi($row))->toEndWith('  7 G')
    ->and(TerminalText::stripAnsi($row))->toStartWith(" > \u{2694}\u{FE0F} ");
});

it('pads an optional icon gutter without truncating multi-cell icons or adding it to plain rows', function () {
  expect(TerminalMenuRow::composeIconLabel('', 'Fixture', 2))->toBe('Fixture')
    ->and(TerminalMenuRow::composeIconLabel('~', 'Fixture', 2))->toBe('~  Fixture')
    ->and(TerminalMenuRow::composeIconLabel('[=>]', 'Fixture', 2))->toBe('[=>] Fixture');
});

it('fits wide styled values and left aligned equipment names without splitting graphemes', function () {
  $price = TerminalMenuRow::compose('Fixture', '<fg=blue>12 界</>', 20, valueWidth: 7);
  $slot = TerminalMenuRow::compose('Slot:', 'Equipment', 30, prefix: ' > ', valueWidth: 10, valueAlignment: 'left');
  expect(TerminalText::displayWidth($price))->toBe(20)
    ->and(TerminalText::stripAnsi($price))->toEndWith('12 界')
    ->and(TerminalText::displayWidth($slot))->toBe(30)
    ->and(TerminalText::stripAnsi($slot))->toEndWith('Equipment ');
});

it('retains fixed slot columns when the equipped name needs clipping', function () {
  $row = TerminalMenuRow::compose('Slot:', str_repeat('Equipment ', 10), 40,
    prefix: ' > ', valueAlignment: 'left', labelWidth: 20);
  $cells = NormalizedRow::fromText($row)->cells;
  expect(count($cells))->toBe(40)
    ->and(TerminalText::stripAnsi($cells[24]))->toBe('E')
    ->and(TerminalText::stripAnsi(implode('', array_slice($cells, 3, 20))))->toBe('Slot:' . str_repeat(' ', 15));
});
