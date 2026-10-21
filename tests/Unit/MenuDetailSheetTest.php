<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuDetailEntry;
use Ichiloto\Engine\UI\Presentation\MenuDetailSheet;
use Ichiloto\Engine\UI\Presentation\MenuDetailStyle;
use Ichiloto\Engine\UI\Presentation\MenuDetailViewport;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

function createSyntheticDetailSheet(): MenuDetailSheet
{
  return new MenuDetailSheet([
    [MenuDetailEntry::createProse('Synthetic subject', 'accent')],
    [MenuDetailEntry::createProse('A paragraph of reading text that wraps across several narrow lines.')],
    [],
    [MenuDetailEntry::createField('Move', "Named move\nIts description continues as value text."),
      MenuDetailEntry::createField('Kind', 'Alpha'),
      MenuDetailEntry::createField('A longer label', 'Beta, Gamma', 'increase')],
  ]);
}

/** @return array{MenuCanvas, MenuDetailSheet, CanvasRectangle, \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas} */
function composeSyntheticDetails(array $theme, int $columns = 40): array
{
  $catalog = new MenuPresentationCatalog(createTestDirectory('menu-details'), ['schema' => 'ichiloto.menu/1', ...$theme]);
  $m = $catalog->metrics;
  $sheet = createSyntheticDetailSheet();
  $bounds = new CanvasRectangle(100, 80, $columns * $m->cellWidth, 20 * $m->cellHeight);
  $view = new MenuCanvas($catalog);
  $view->details('details', $sheet->layout($columns, $catalog->createDetailStyle()),
    $sheet->createPage(new TextViewport(), new MenuDetailViewport($columns, (int)$bounds->height, $m->cellHeight), $catalog->createDetailStyle()), $bounds);
  return [$view, $sheet, $bounds, $view->finish(), $catalog];
}

it('aligns every field value in one column while prose keeps the full width', function (MenuDetailStyle $style) {
  $layout = createSyntheticDetailSheet()->layout(40, $style);
  $fields = array_values(array_filter($layout->lines, fn($line) => $line->isField));
  $prose = array_values(array_filter($layout->lines, fn($line) => !$line->isField && $line->text !== ''));
  // The style's minimum label column applies while a useful value column remains.
  $column = max(TextViewport::measure('A longer label' . $style->labelSuffix) + $style->gapCells,
    40 - ($style->minLabelCells + $style->gapCells) >= $style->minValueCells ? $style->minLabelCells + $style->gapCells : 0);
  expect(array_unique(array_column($fields, 'column')))->toBe([$column])
    ->and(array_column($prose, 'column'))->each->toBe(0)
    ->and(array_column(array_filter($fields, fn($line) => $line->label !== ''), 'label'))
    ->toBe(['Move' . $style->labelSuffix, 'Kind' . $style->labelSuffix, 'A longer label' . $style->labelSuffix])
    ->and(array_filter($fields, fn($line) => $line->closesField))->toHaveCount(3);
  foreach ($layout->getWrappedLines() as [, $text]) { expect(TextViewport::measure($text))->toBeLessThanOrEqual(40); }
  expect(array_column($layout->getWrappedLines(), 1))->toContain(str_pad('Kind' . $style->labelSuffix, $column) . 'Alpha');
})->with([[new MenuDetailStyle()], [MenuDetailStyle::createTerminal()]]);

it('stacks a label above its indented value when the value column would be too narrow', function () {
  $style = MenuDetailStyle::createTerminal();
  $layout = createSyntheticDetailSheet()->layout(20, $style);
  $kind = array_values(array_filter($layout->lines, fn($line) => $line->isField && $line->label === 'Kind' . $style->labelSuffix));
  $index = array_search($kind[0], $layout->lines, true);
  expect($kind[0]->text)->toBe('')->and($layout->lines[$index + 1]->text)->toBe('Alpha')
    ->and($layout->lines[$index + 1]->column)->toBe($style->stackedIndentCells)
    ->and($layout->lines[$index + 1]->closesField)->toBeTrue();
});

it('pages, scrolls to the end and keeps the reading position across reflow', function () {
  $sheet = createSyntheticDetailSheet();
  $viewport = new TextViewport();
  $offsets = array_column($sheet->layout(24)->getWrappedLines(), 0);
  $sorted = $offsets;
  sort($sorted);
  expect($offsets)->toBe(array_values(array_unique($offsets)))->and($offsets)->toBe($sorted);
  $page = $sheet->createPage($viewport, new MenuDetailViewport(24, 4));
  for ($i = 0; $i < 40 && $page->first + $page->rows < $page->total; $i++) {
    $viewport->scroll(1, $page);
    $page = $sheet->createPage($viewport, new MenuDetailViewport(24, 4));
  }
  expect($page->first + $page->rows)->toBe($page->total)->and(implode("\n", $page->lines))->toContain('Beta, Gamma');
  expect(implode("\n", $sheet->createPage($viewport, new MenuDetailViewport(60, 4), MenuDetailStyle::createTerminal())->lines))->toContain('Beta, Gamma');
  $viewport->reset();
  expect($sheet->createPage($viewport, new MenuDetailViewport(24, 4))->first)->toBe(0);
});

it('rejects blank entries, multi-line labels and unsupported theme detail styles', function () {
  expect(fn() => MenuDetailEntry::createProse('  '))->toThrow(InvalidArgumentException::class)
    ->and(fn() => MenuDetailEntry::createField("Two\nlines", 'Value'))->toThrow(InvalidArgumentException::class)
    ->and(fn() => MenuDetailEntry::createField('Label', ''))->toThrow(InvalidArgumentException::class)
    ->and((new MenuDetailSheet([[], []]))->layout(10)->lines)->toHaveCount(1);
  foreach ([['valueAlignment' => ['text' => 'center']], ['valueAlignment' => ['unknown' => 'left']], ['valuePlacement' => ['icons' => 'above']], ['gapCells' => -1], ['separators' => 'yes'], ['unknown' => 1],
    ['labelColor' => 'not-a-theme-role']] as $details) {
    expect(fn() => new MenuPresentationCatalog(createTestDirectory('menu-details'), ['schema' => 'ichiloto.menu/1', 'details' => $details]))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('paints field records with the theme separators and colours, never prose, under two themes', function (array $data) {
  [$view, $sheet, $bounds, $frame, $theme] = composeSyntheticDetails($data);
  $rules = array_values(array_filter($frame->textLayers, fn($layer) => str_contains($layer->id, '-separator')));
  $runs = array_merge(...array_map(fn($layer) => $layer->runs, $rules));
  expect(array_sum(array_map(fn($layer) => count($layer->runs), $rules)))->toBe(3)
    ->and(array_unique(array_map(fn($run) => $run->background, $runs), SORT_REGULAR))->toEqual([$theme->colors['edge']])
    ->and(array_unique(array_column($rules, 'opacity')))->toBe([$theme->rows->metrics->recordSeparatorOpacity]);
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'details');
  $kind = array_find($text->runs, fn($run) => $run->text === 'Kind');
  $value = array_find($text->runs, fn($run) => $run->text === 'Alpha' && $run->row === $kind->row);
  expect($kind->column)->toBe(0)->and($value->column)->toBe(TextViewport::measure('A longer label') + 1)
    ->and(array_find($text->runs, fn($run) => $run->text === 'Synthetic subject')->foreground)->toEqual($theme->colors['accent'])
    ->and(array_find($text->runs, fn($run) => $run->text === 'Beta, Gamma')->foreground)->toEqual($theme->colors['increase'])
    ->and($kind->foreground)->toEqual($theme->colors['text']);
  expect(fn() => $view->details('wrong', $sheet->layout(41, $theme->createDetailStyle()), $sheet->createPage(new TextViewport(), new MenuDetailViewport(40, (int)$bounds->height, $theme->metrics->cellHeight)), $bounds))
    ->toThrow(RuntimeException::class);
})->with([[[]], [['metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34],
  'colors' => ['text' => [43, 30, 10], 'edge' => [120, 90, 40], 'accent' => [150, 40, 20]],
  'rowMetrics' => ['separatorWidth' => 2, 'recordSeparatorOpacity' => 0.5]]]]);

it('lets a theme restyle field labels, values, alignment and separators without owner or code changes', function () {
  [, , , $frame, $theme] = composeSyntheticDetails(['details' => ['labelSuffix' => ' -', 'gapCells' => 3,
    'valueAlignment' => ['text' => 'right'], 'labelColor' => 'accent', 'valueColor' => 'disabled', 'separators' => false]]);
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'details');
  $kind = array_find($text->runs, fn($run) => $run->text === 'Kind -');
  $alpha = array_find($text->runs, fn($run) => $run->text === 'Alpha' && $run->row === $kind->row);
  expect($theme->createDetailStyle()->getAlignment(\Ichiloto\Engine\UI\Presentation\MenuDetailKind::TEXT))->toBe(HorizontalAlignment::RIGHT)
    ->and($kind->foreground)->toEqual($theme->colors['accent'])
    ->and($alpha->foreground)->toEqual($theme->colors['disabled'])
    ->and($alpha->column + mb_strlen('Alpha'))->toBe(40)
    ->and(array_find($text->runs, fn($run) => $run->text === 'Beta, Gamma')->foreground)->toEqual($theme->colors['increase'])
    ->and(array_filter($frame->textLayers, fn($layer) => str_contains($layer->id, '-separator')))->toBeEmpty();
});

it('applies a record design as theme data: stacked wrapping values, right short values, minimum record height', function () {
  $data = ['details' => ['valueAlignment' => ['text' => 'right'], 'stackWrappedValues' => true, 'stackedIndentCells' => 0,
    'labelColor' => 'disabled', 'recordMinHeight' => 40]];
  [, $sheet, $bounds, $frame, $theme] = composeSyntheticDetails($data);
  $m = $theme->metrics;
  $layout = $sheet->layout(40, $theme->createDetailStyle());
  $move = array_values(array_filter($layout->lines, fn($line) => $line->entry === 2));
  expect($move[0]->label)->toBe('Move')->and($move[0]->text)->toBe('')
    ->and(array_column(array_slice($move, 1), 'column'))->each->toBe(0)
    ->and($move[1]->text)->toBe('Named move');
  $kind = array_find($layout->lines, fn($line) => $line->label === 'Kind');
  expect($kind->column + mb_strlen($kind->text))->toBe(40);
  // Every field record is at least 40px; prose keeps one line height; text centres in the record.
  $metrics = $layout->measureLines($m->cellHeight);
  foreach (array_unique(array_filter(array_column($layout->lines, 'entry'), fn($entry) => $entry !== null)) as $entry) {
    $indexes = array_keys(array_filter($layout->lines, fn($line) => $line->entry === $entry));
    $height = array_sum(array_map(fn($i) => $metrics[$i]['height'], $indexes));
    expect($height)->toBe($layout->lines[$indexes[0]]->isField ? max(40, count($indexes) * $m->cellHeight) : count($indexes) * $m->cellHeight);
  }
  $kindIndex = array_search($kind, $layout->lines, true);
  expect($metrics[$kindIndex])->toBe(['height' => 40, 'top' => intdiv(40 - $m->cellHeight, 2)]);
  $text = array_merge(...array_map(fn($layer) => array_map(fn($run) => [$layer->y + $run->row * $layer->grid->cellHeight, $run], $layer->runs),
    array_filter($frame->textLayers, fn($layer) => str_starts_with($layer->id, 'details') && !str_contains($layer->id, 'separator'))));
  $label = array_find($text, fn($item) => $item[1]->text === 'Kind');
  expect($label[1]->foreground)->toEqual($theme->colors['disabled']);
  $rules = array_values(array_filter($frame->textLayers, fn($layer) => str_contains($layer->id, '-separator')));
  expect(array_sum(array_map(fn($layer) => count($layer->runs), $rules)))->toBe(3);
  // Paging counts pixels and shows whole lines; scrolling reaches the last record.
  $viewport = new TextViewport();
  $area = new \Ichiloto\Engine\UI\Presentation\MenuDetailViewport(40, 100, $m->cellHeight);
  $page = $sheet->createPage($viewport, $area, $theme->createDetailStyle());
  for ($i = 0; $i < 40 && $page->first + count($page->lines) < $page->total; $i++) {
    expect(array_sum(array_map(fn($i) => $metrics[$i]['height'], range($page->first, $page->first + count($page->lines) - 1))))
      ->toBeLessThanOrEqual(100);
    $viewport->scroll(1, $page);
    $page = $sheet->createPage($viewport, $area, $theme->createDetailStyle());
  }
  expect($page->first + count($page->lines))->toBe($page->total)->and(implode("\n", $page->lines))->toContain('Beta, Gamma');
});
