<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\JournalDocument;
use Ichiloto\Engine\UI\Presentation\JournalMenuContent;
use Ichiloto\Engine\UI\Presentation\JournalSection;
use Ichiloto\Engine\UI\Presentation\MenuControls;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\QuestJournalPresentation;
use Ichiloto\Engine\UI\Text\TextViewport;

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-menu-controls-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  $chunk = static fn(string $type, string $bytes): string => pack('N', strlen($bytes)) . $type . $bytes
    . pack('N', crc32($type . $bytes));
  file_put_contents($this->root . '/surface.png', "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', 32, 32, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xFF\xFF\xFF\xFF", 32), 32))) . $chunk('IEND', ''));
});

afterEach(function () {
  unlink($this->root . '/surface.png');
  rmdir($this->root);
});

function getScrollControlTheme(string $root, bool $art, array $metrics = []): MenuPresentationCatalog
{
  return new MenuPresentationCatalog($root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'metrics' => $metrics,
    'frames' => $art ? ['scroll.track' => ['asset' => 'surface.png'],
      'scroll.thumb' => ['asset' => 'surface.png', 'cuts' => [0, 12, 0, 12]]] : [],
    'icons' => $art ? ['navigation.up' => 'surface.png', 'navigation.down' => 'surface.png'] : []]);
}

it('renders shared scroll artwork and fallback at every endpoint without overlap', function (float $ratio, float $visible, bool $art) {
  $theme = getScrollControlTheme($this->root, $art);
  $controls = new MenuControls($theme);
  $box = new CanvasRectangle(50, 20, 24, 256);
  $controls->renderScrollbar('scroll', $box, (100 - $visible) * $ratio, $visible, 100);
  $frame = $controls->finish(100, 300);
  $pieces = [...$frame->images, ...$frame->textLayers];
  $track = new CanvasRectangle(56, 48, 12, 200);
  $thumbHeight = max(28, 200 * $visible / 100);
  $thumb = new CanvasRectangle(56, 48 + (200 - $thumbHeight) * $ratio, 12, $thumbHeight);
  foreach (['track' => $track, 'thumb' => $thumb] as $role => $expected) {
    $surface = array_values(array_filter($pieces, fn($piece) => str_starts_with($piece->id, 'scroll-' . $role)));
    expect($surface)->not->toBeEmpty();
    foreach ($surface as $piece) { expect($piece->clipRect)->toEqual($expected); }
  }
  $up = array_find($pieces, fn($piece) => str_starts_with($piece->id, 'scroll-up'));
  $down = array_find($pieces, fn($piece) => str_starts_with($piece->id, 'scroll-down'));
  expect($up->opacity)->toBe($ratio === 0.0 ? 0.35 : 1.0)
    ->and($down->opacity)->toBe($ratio === 1.0 ? 0.35 : 1.0)
    ->and($up->clipRect->y + $up->clipRect->height + 4)->toEqual($track->y)
    ->and($track->y + $track->height + 4)->toEqual($down->clipRect->y);
  if (!$art) {
    expect($up->runs[0]->text)->toBe("\u{2227}")->and($down->runs[0]->text)->toBe("\u{2228}");
  }
  foreach ($pieces as $piece) { $piece->clipRect->assertWithin(100, 300); }
  $frame->toArray();
})->with([0.0, 0.5, 1.0])->with([2.0, 25.0])->with([false, true]);

it('uses authored scrollbar metrics and clamps the minimum thumb to short tracks', function () {
  $theme = getScrollControlTheme($this->root, false, ['scrollbarWidth' => 30, 'scrollbarTrackWidth' => 8,
    'scrollbarArrowGap' => 6, 'scrollbarMinThumbHeight' => 40]);
  $controls = new MenuControls($theme);
  $controls->renderScrollbar('scroll', new CanvasRectangle(0, 0, 30, 90), 99, 1, 100);
  $pieces = array_column($controls->finish(100, 100)->textLayers, null, 'id');
  expect($pieces['scroll-track']->clipRect)->toEqual(new CanvasRectangle(11, 36, 8, 18))
    ->and($pieces['scroll-thumb']->clipRect)->toEqual($pieces['scroll-track']->clipRect)
    ->and($pieces['scroll-down']->opacity)->toBe(0.35);
});

it('omits unnecessary scrollbars and rejects invalid extents before painting', function () {
  $theme = getScrollControlTheme($this->root, false);
  $box = new CanvasRectangle(0, 0, 24, 200);
  foreach ([0, 5, 10] as $total) {
    $controls = new MenuControls($theme);
    $controls->renderScrollbar('scroll', $box, 0, 10, $total);
    expect($controls->finish(100, 300)->textLayers)->toBeEmpty();
  }
  foreach ([[-1, 10, 100], [91, 10, 100], [0, 0, 100], [0, 10, -1], [NAN, 10, 100],
    [0, INF, 100], [0, 10, INF]] as [$offset, $visible, $total]) {
    $controls = new MenuControls($theme);
    expect(fn() => $controls->renderScrollbar('scroll', $box, $offset, $visible, $total))->toThrow(InvalidArgumentException::class)
      ->and($controls->finish(100, 300)->textLayers)->toBeEmpty();
  }
  $controls = new MenuControls($theme);
  expect(fn() => $controls->renderScrollbar('scroll', new CanvasRectangle(0, 0, 24, 56), 0, 1, 10))
    ->toThrow(InvalidArgumentException::class);
  foreach ([['scrollbarWidth' => 65], ['scrollbarTrackWidth' => 25], ['scrollbarTrackWidth' => 0],
    ['scrollbarMinThumbHeight' => 0], ['scrollbarArrowGap' => -1]] as $metrics) {
    expect(fn() => getScrollControlTheme($this->root, false, $metrics))->toThrow(InvalidArgumentException::class);
  }
});

it('uses the same themed scrollbar for quest prose without painting over text or changing reading state', function (int $width, int $height, bool $art) {
  $theme = getScrollControlTheme($this->root, $art);
  $document = new JournalDocument([new JournalSection('Objectives',
    array_map(fn($i) => ['text' => 'Objective ' . $i], range(1, 80)))]);
  $content = new JournalMenuContent('Quests', ['Active', 'Completed'], 0, '',
    [['label' => 'A journey', 'values' => ['', 'In progress']]], 0, 'No quests', 'journey',
    $document->text, '', true, true, $document);
  $reading = new TextViewport();
  $reading->setText($document->text);
  [$columns, $rows] = QuestJournalPresentation::pageSize($content, $theme, $width, $height);
  for ($i = 0; $i < 100; $i++) {
    $page = $reading->page($columns, $rows);
    $frame = QuestJournalPresentation::compose($content, $theme, $page, width: $width, height: $height);
    expect($reading->page($columns, $rows))->toEqual($page);
    $pieces = [...$frame->images, ...$frame->textLayers];
    $track = array_find($pieces, fn($piece) => str_starts_with($piece->id, 'journal-scroll-track'))->clipRect;
    $text = array_find($frame->textLayers, fn($piece) => str_starts_with($piece->id, 'journal-text'));
    expect($text->clipRect->x + $text->clipRect->width)->toBeLessThan($track->x);
    $frame->toArray();
    if ($page->first + $page->rows >= $page->total) { break; }
    $reading->scroll(1, $page);
  }
  expect($page->first + $page->rows)->toBe($page->total);
  $down = array_find($pieces, fn($piece) => str_starts_with($piece->id, 'journal-scroll-down'));
  expect($down->opacity)->toBe(0.35);
})->with([[1350, 720], [960, 540], [736, 414]])->with([false, true]);
