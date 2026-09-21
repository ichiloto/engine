<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowColumn;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\UI\Presentation\MenuRowValue;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;

it('keeps ordinary words and hyphenated item labels intact at available word boundaries', function (string $text, int $cells, array $expected) {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 300, 200), wrapText: true);
  expect(MenuTextWrap::lines($text, $cells))->toBe($expected)
    ->and(MenuCanvas::wrap($text, $cells))->toBe($expected)
    ->and($layout->lines($text, $cells))->toBe($expected)
    ->and(implode('', $expected))->toBe($text);
})->with([
  ['Read the manual', 13, ['Read the ', 'manual']],
  ['Use S-Potion now', 10, ['Use ', 'S-Potion ', 'now']],
  ['One two three', 7, ['One two', ' three']],
  ['abc def ghi', 7, ['abc def', ' ghi']],
]);

it('preserves explicit paragraphs blank lines tabs and trailing whitespace', function () {
  $source = "First\r\n\r\nSecond\rThird\titem \n";
  $expected = ['First', '', 'Second', 'Third    item ', ''];
  expect(MenuCanvas::wrap($source, 30))->toBe($expected)
    ->and((new MenuRowLayout(new CanvasRectangle(0, 0, 300, 200), wrapText: true))->lines($source, 30))->toBe($expected)
    ->and(MenuTextWrap::lines('', 8))->toBe(['']);
});

it('splits only overlong unbroken tokens using the Canvas codepoint budget', function () {
  expect(MenuTextWrap::lines('Before abcdefghijk after', 6))->toBe(['Before', ' abcde', 'fghijk', ' after']);
  $text = "\u{65E5}\u{672C}\u{8A9E}\u{6587}\u{7AE0} cafe\u{0301} \u{1F680}";
  $lines = MenuTextWrap::lines($text, 4);
  expect(implode('', $lines))->toBe($text);
  foreach ($lines as $line) { expect(mb_check_encoding($line, 'UTF-8'))->toBeTrue()->and(mb_strlen($line, 'UTF-8'))->toBeLessThanOrEqual(4); }
  expect(MenuTextWrap::lines("a\u{00A0}b c", 4))->toBe(["a\u{00A0}b ", 'c']);
});

it('retains codepoints without lost whitespace or over-budget rows across narrow widths', function () {
  $source = "manual S-Potion \u{65E5}\u{672C}\u{8A9E} cafe\u{0301}   abcdefghijklmnop ";
  foreach (range(1, 40) as $width) {
    $lines = MenuTextWrap::lines($source, $width);
    expect(implode('', $lines))->toBe($source);
    foreach ($lines as $line) { expect(mb_strlen($line, 'UTF-8'))->toBeLessThanOrEqual($width); }
  }
});

it('measures and paints wrapped labels values and centered commands through the same lines', function () {
  $theme = new MenuPresentationCatalog(sys_get_temp_dir(), ['schema' => 'ichiloto.menu/1']);
  $view = new MenuCanvas($theme, 500, 500);
  $layout = new MenuRowLayout(new CanvasRectangle(20, 20, 240, 200), [new MenuRowColumn(5)], 40, 10, 24, true);
  $row = new MenuRow('record', 'Use S-Potion', [new MenuRowValue('Cost now')]);
  $height = $layout->heightFor($row, $theme->rows->metrics, false);
  $view->rows('wrap', [$row], $layout);
  $commandLayout = new MenuRowLayout(new CanvasRectangle(20, 250, 170, 160), rowHeight: 40, wrapText: true);
  $view->rows('wrap', [new MenuRow('command', 'Read the manual', kind: MenuRowKind::BUTTON)], $commandLayout);
  $view->prose('paragraph', 'Read the manual', new CanvasRectangle(280, 20, 130, 100));
  $canvas = $view->finish();
  $layers = array_column($canvas->textLayers, null, 'id');
  $record = $layers['wrap-record-text'];
  expect($record->clipRect->height)->toBe((float)$height)
    ->and(implode('', array_column($layers['paragraph']->runs, 'text')))->toBe('Read the manual');
  expect(array_column($layers['paragraph']->runs, 'text'))->toBe(['Read the ', 'manual']);
  $command = $layers['wrap-command-text'];
  foreach ($command->runs as $run) {
    $center = $command->x + ($run->column + mb_strlen($run->text) / 2) * $command->grid->cellWidth;
    expect(abs($center - ($command->clipRect->x + $command->clipRect->width / 2)))->toBeLessThanOrEqual($command->grid->cellWidth / 2);
  }
  expect(count($canvas->textLayers))->toBeLessThanOrEqual(64)->and($canvas->toArray()['width'])->toBe(500);
});

it('preserves the opt-in wrapping guard and invalid-width diagnostics', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 200, 100));
  expect(fn() => $layout->lines('Too long', 3))->toThrow(InvalidArgumentException::class)
    ->and(fn() => MenuCanvas::wrap('Text', 0))->toThrow(RuntimeException::class)
    ->and(fn() => MenuTextWrap::lines("\xFF", 5))->toThrow(InvalidArgumentException::class);
});
