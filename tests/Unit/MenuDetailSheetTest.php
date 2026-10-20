<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuDetailEntry;
use Ichiloto\Engine\UI\Presentation\MenuDetailSheet;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Text\TerminalMenuRow;
use Ichiloto\Engine\UI\Text\TextViewport;

function createSyntheticDetailSheet(): MenuDetailSheet
{
  return new MenuDetailSheet([
    [MenuDetailEntry::createProse('Synthetic subject', 'accent')],
    [MenuDetailEntry::createProse('A paragraph of reading text that wraps across several narrow lines.')],
    [],
    [MenuDetailEntry::createField('Move', "Named move\nIts description continues as value text."),
      MenuDetailEntry::createField('Kind', 'Alpha'),
      MenuDetailEntry::createField('A longer label', 'Beta, Gamma')],
  ]);
}

it('aligns every field value in one column while prose keeps the full width', function (string $suffix) {
  $layout = createSyntheticDetailSheet()->layout(40, $suffix);
  $fields = array_values(array_filter($layout->lines, fn($line) => $line->isField));
  $prose = array_values(array_filter($layout->lines, fn($line) => !$line->isField && $line->text !== ''));
  $column = TextViewport::measure('A longer label' . $suffix) + MenuDetailSheet::GAP_CELLS;
  expect(array_unique(array_column($fields, 'column')))->toBe([$column])
    ->and(array_column($prose, 'column'))->each->toBe(0)
    ->and(array_filter($fields, fn($line) => $line->label !== ''))->toHaveCount(3)
    ->and(array_filter($fields, fn($line) => $line->closesField))->toHaveCount(3);
  foreach ($layout->getWrappedLines() as [, $text]) { expect(TextViewport::measure($text))->toBeLessThanOrEqual(40); }
  expect(array_column(array_filter($fields, fn($line) => $line->label !== ''), 'label'))
    ->toBe(['Move' . $suffix, 'Kind' . $suffix, 'A longer label' . $suffix]);
  expect(array_column($layout->getWrappedLines(), 1))->toContain(
    str_pad('Kind' . $suffix, $column) . 'Alpha');
})->with(['', TerminalMenuRow::LABEL_SUFFIX]);

it('stacks a label above its indented value when the value column would be too narrow', function () {
  $layout = createSyntheticDetailSheet()->layout(20, TerminalMenuRow::LABEL_SUFFIX);
  $kind = array_values(array_filter($layout->lines, fn($line) => $line->isField && $line->label === 'Kind:'));
  $index = array_search($kind[0], $layout->lines, true);
  expect($kind[0]->text)->toBe('')->and($layout->lines[$index + 1]->text)->toBe('Alpha')
    ->and($layout->lines[$index + 1]->column)->toBe(MenuDetailSheet::STACKED_INDENT_CELLS)
    ->and($layout->lines[$index + 1]->closesField)->toBeTrue();
});

it('pages, scrolls to the end and keeps the reading position on the same record across reflow', function () {
  $sheet = createSyntheticDetailSheet();
  $viewport = new TextViewport();
  $offsets = array_column($sheet->layout(24)->getWrappedLines(), 0);
  $sorted = $offsets;
  sort($sorted);
  expect($offsets)->toBe(array_values(array_unique($offsets)))->and($offsets)->toBe($sorted);
  $page = $sheet->createPage($viewport, 24, 4);
  for ($i = 0; $i < 40 && $page->first + $page->rows < $page->total; $i++) {
    $viewport->scroll(1, $page);
    $page = $sheet->createPage($viewport, 24, 4);
  }
  expect($page->first + $page->rows)->toBe($page->total)->and(implode("\n", $page->lines))->toContain('Beta, Gamma');
  $wide = $sheet->createPage($viewport, 60, 4, TerminalMenuRow::LABEL_SUFFIX);
  expect(implode("\n", $wide->lines))->toContain('Beta, Gamma');
  $viewport->reset();
  expect($sheet->createPage($viewport, 24, 4)->first)->toBe(0);
});

it('rejects blank entries and multi-line labels', function () {
  expect(fn() => MenuDetailEntry::createProse('  '))->toThrow(InvalidArgumentException::class)
    ->and(fn() => MenuDetailEntry::createField("Two\nlines", 'Value'))->toThrow(InvalidArgumentException::class)
    ->and(fn() => MenuDetailEntry::createField('Label', ''))->toThrow(InvalidArgumentException::class)
    ->and((new MenuDetailSheet([[], []]))->layout(10)->lines)->toHaveCount(1);
});

it('paints field records with the theme separators and colours, never prose, under two themes', function (array $data) {
  $theme = new MenuPresentationCatalog(createTestDirectory('menu-details'), ['schema' => 'ichiloto.menu/1', ...$data]);
  $m = $theme->metrics;
  $sheet = createSyntheticDetailSheet();
  $columns = 40;
  $bounds = new CanvasRectangle(100, 80, $columns * $m->cellWidth, 20 * $m->cellHeight);
  $layout = $sheet->layout($columns);
  $view = new MenuCanvas($theme);
  $view->details('details', $layout, $sheet->createPage(new TextViewport(), $columns, 20), $bounds);
  $frame = $view->finish();
  $rules = array_values(array_filter($frame->textLayers, fn($layer) => str_contains($layer->id, '-separator')));
  $runs = array_merge(...array_map(fn($layer) => $layer->runs, $rules));
  expect(array_sum(array_map(fn($layer) => count($layer->runs), $rules)))->toBe(3)
    ->and(array_unique(array_map(fn($run) => $run->background, $runs), SORT_REGULAR))->toEqual([$theme->colors['edge']])
    ->and(array_unique(array_column($rules, 'opacity')))->toBe([$theme->rows->metrics->recordSeparatorOpacity]);
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'details');
  $kind = array_find($text->runs, fn($run) => $run->text === 'Kind');
  $value = array_find($text->runs, fn($run) => $run->text === 'Alpha' && $run->row === $kind->row);
  expect($kind->column)->toBe(0)->and($value->column)->toBe(TextViewport::measure('A longer label') + MenuDetailSheet::GAP_CELLS)
    ->and(array_find($text->runs, fn($run) => $run->text === 'Synthetic subject')->foreground)->toEqual($theme->colors['accent'])
    ->and($kind->foreground)->toEqual($theme->colors['text']);
  expect(fn() => $view->details('wrong', $sheet->layout($columns + 1), $sheet->createPage(new TextViewport(), $columns, 20), $bounds))
    ->toThrow(RuntimeException::class);
})->with([[[]], [['metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34],
  'colors' => ['text' => [43, 30, 10], 'edge' => [120, 90, 40], 'accent' => [150, 40, 20]],
  'rowMetrics' => ['separatorWidth' => 2, 'recordSeparatorOpacity' => 0.5]]]]);
