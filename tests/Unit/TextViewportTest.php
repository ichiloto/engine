<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Text\TextViewport;

it('reaches every line in both directions and clamps repeated boundary input', function () {
  $view = new TextViewport();
  $view->setText(implode("\n", array_map(fn($i) => 'Line ' . $i, range(0, 80))));
  $seen = [];
  for ($i = 0; $i < 100; $i++) {
    $page = $view->page(30, 4);
    array_push($seen, ...$page->lines);
    $view->scroll(1, $page);
  }
  expect(array_values(array_unique($seen)))->toBe(array_map(fn($i) => 'Line ' . $i, range(0, 80)))
    ->and($view->page(30, 4)->range())->toBe('Lines 78-81 / 81');
  for ($i = 0; $i < 100; $i++) { $view->scroll(-1, $view->page(30, 4)); }
  expect($view->page(30, 4)->first)->toBe(0);
});

it('preserves graphemes explicit blank lines and complete localized text within both cell budgets', function () {
  $text = "\u{65E5}\u{672C}\u{8A9E} cafe\u{0301} \u{1F469}\u{200D}\u{1F4BB}\n\n" . str_repeat('Localized word ', 40);
  $wrapped = TextViewport::wrap($text, 12);
  $rebuilt = '';
  foreach ($wrapped as $index => [$offset, $line]) {
    expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual(12)->and(mb_strlen($line))->toBeLessThanOrEqual(12);
    $next = $wrapped[$index + 1][0] ?? strlen($text);
    $rebuilt .= substr($text, $offset, $next - $offset);
  }
  expect($rebuilt)->toBe($text)->and(array_column($wrapped, 1))->toContain('');
  expect(implode('', array_column($wrapped, 1)))->toContain("e\u{0301}")->toContain("\u{1F469}\u{200D}\u{1F4BB}");
});

it('reflows around the source anchor without navigating during snapshot reads', function () {
  $view = new TextViewport();
  $view->setText(str_repeat('abcdefghijklmnopqrstuvwxyz ', 20));
  for ($i = 0; $i < 6; $i++) { $view->scroll(1, $view->page(20, 3)); }
  $before = clone $view;
  $wide = $view->page(40, 5);
  $narrow = $view->page(10, 2);
  expect($view)->toEqual($before)->and($wide->first)->toBe(3)->and($narrow->first)->toBe(9);
  $view->setText('Changed');
  $view->scroll(1, $wide);
  expect($view->page(20, 3)->lines)->toBe(['Changed'])->and($view->page(20, 3)->first)->toBe(0);
});

it('keeps fitting words intact and makes shortened list previews explicit', function () {
  expect(array_column(TextViewport::wrap('Read the manual with S-Potion', 13), 1))->toBe(['Read the ', 'manual with ', 'S-Potion']);
  expect(TextViewport::preview('Short', 10))->toBe('Short')
    ->and(TextViewport::preview('Long authored name', 10))->toEndWith('...')
    ->and(TextViewport::preview("First\nSecond", 20))->toBe('First...');
});

it('handles empty content and rejects geometry that cannot hold one complete grapheme', function () {
  $view = new TextViewport();
  expect($view->page(20, 3)->lines)->toBe([''])->and($view->page(20, 3)->range())->toBe('Lines 1-1 / 1');
  expect(fn() => $view->page(0, 1))->toThrow(InvalidArgumentException::class);
  expect(fn() => TextViewport::wrap("\u{65E5}", 1))->toThrow(InvalidArgumentException::class);
});
