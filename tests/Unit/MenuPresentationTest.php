<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowArtwork;
use Ichiloto\Engine\UI\Presentation\MenuRowColumn;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\UI\Presentation\MenuRowMetrics;
use Ichiloto\Engine\UI\Presentation\MenuRowPainter;
use Ichiloto\Engine\UI\Presentation\MenuRowSkin;
use Ichiloto\Engine\UI\Presentation\MenuRowValue;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

function menuPresentationSkin(): MenuRowSkin
{
  return new MenuRowSkin([
    'text' => PresentationColor::rgb(230, 237, 245),
    'selected' => PresentationColor::rgb(34, 63, 94),
    'accent' => PresentationColor::rgb(192, 154, 91),
    'focus' => PresentationColor::rgb(255, 255, 255),
    'disabled' => PresentationColor::rgb(99, 110, 122),
    'edge' => PresentationColor::rgb(82, 104, 129),
  ]);
}

/** Synthetic replaceable PNGs, not approval gates on project artwork. */
function menuPresentationPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes): string => pack('N', strlen($bytes)) . $type . $bytes
    . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xFF\xFF\xFF\xFF", $width), $height)))
    . $chunk('IEND', ''));
}

beforeEach(function () {
  $this->menuRoot = sys_get_temp_dir() . '/ichiloto-menu-rows-' . bin2hex(random_bytes(5));
  mkdir($this->menuRoot);
  $this->debugStatics = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->menuRoot]);
  foreach (['staff', 'slot', 'unknown', 'cursor'] as $name) {
    menuPresentationPng($this->menuRoot . '/' . $name . '.png', 20, 40);
  }
  $this->menuIcons = new MenuIconRegistry($this->menuRoot, [
    'weapon.staff' => 'staff.png', 'slot.head' => 'slot.png', 'unknown' => 'unknown.png',
  ], 'cursor.png');
});

afterEach(function () {
  foreach ($this->debugStatics as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
  foreach (glob($this->menuRoot . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->menuRoot);
});

it('draws in-bounds separators only for real records and headings including named empty slots', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(20, 10, 400, 179), [new MenuRowColumn(10)]);
  $rows = [
    new MenuRow('item', 'Tonic', [new MenuRowValue('2')]),
    new MenuRow('head', 'Head:', [new MenuRowValue('')], icon: EquipmentSlotType::HEAD),
    new MenuRow('heading', '', [new MenuRowValue('Current')], MenuRowKind::HEADING),
    new MenuRow('command', 'Equip', kind: MenuRowKind::COMMAND),
  ];
  $frame = MenuRowPainter::compose(500, 200, 'list', $rows, $layout, menuPresentationSkin(), $this->menuIcons);
  $layers = array_column($frame->textLayers, null, 'id');
  expect($layout->capacity())->toBe(4)->and($layers)->not->toHaveKey('list-command-separator');
  foreach (['item', 'head', 'heading'] as $index => $id) {
    $line = $layers['list-' . $id . '-separator'];
    expect($line->clipRect->y)->toBe(49.0 + 40 * $index)
      ->and($line->clipRect->height)->toBe(1.0)
      ->and($line->opacity)->toBe($id === 'heading' ? 1.0 : 64 / 255);
  }
  expect(array_column($layers['list-head-text']->runs, 'text'))->toBe(['Head:']);
  foreach ($frame->textLayers as $text) {
    expect($text->clipRect->y + $text->clipRect->height)->toBeLessThanOrEqual(170.0);
  }
  expect(MenuRowPainter::compose(500, 200, 'empty', [], $layout, menuPresentationSkin())->textLayers)->toBe([]);
  expect(fn() => new MenuRow('blank', ''))->toThrow(InvalidArgumentException::class, 'Blank space');
});

it('keeps all numeric columns right aligned and comparison indicators independently supplied', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 120), [
    new MenuRowColumn(6), new MenuRowColumn(2, HorizontalAlignment::CENTER), new MenuRowColumn(6),
    new MenuRowColumn(1, HorizontalAlignment::CENTER),
  ], rowHeight: 34, cellWidth: 8, cellHeight: 20);
  $mint = PresentationColor::rgb(150, 210, 180);
  $rows = array_map(fn($index) => new MenuRow('stat-' . $index, 'HP', [
    new MenuRowValue(['9', '1,234', '10'][$index]), new MenuRowValue('>'),
    new MenuRowValue(['10', '1,235', '9'][$index], $mint), new MenuRowValue('+', $mint),
  ]), range(0, 2));
  $frame = MenuRowPainter::compose(400, 120, 'stats', $rows, $layout, menuPresentationSkin());
  $rightEdges = [];
  foreach (array_filter($frame->textLayers, fn($text) => str_ends_with($text->id, '-text')) as $text) {
    $rightEdges[] = array_map(fn($run) => $text->x + ($run->column + mb_strlen($run->text)) * $text->grid->cellWidth,
      [$text->runs[1], $text->runs[3]]);
    expect($text->runs[3]->foreground)->toEqual($mint)->and($text->runs[4]->text)->toBe('+');
  }
  expect($rightEdges[0])->toBe($rightEdges[1])->toBe($rightEdges[2]);
});

it('centers the complete command label regardless of icons selection focus or cursor drift', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(21.5, 0, 401, 40));
  $row = new MenuRow('equip', 'Equip', kind: MenuRowKind::COMMAND, icon: WeaponType::STAFF, selected: true, focused: true);
  $frames = array_map(fn($time) => MenuRowPainter::compose(450, 40, 'commands', [$row], $layout,
    menuPresentationSkin(), $this->menuIcons, $time), [0, 0.6]);
  $text = array_column($frames[0]->textLayers, null, 'id')['commands-equip-text'];
  expect($text->x + $text->bounds->width / 2)->toBe(222.0)
    ->and($frames[0]->textLayers)->toEqual($frames[1]->textLayers);
  $first = array_column($frames[0]->images, null, 'id');
  $second = array_column($frames[1]->images, null, 'id');
  expect($first['commands-equip-icon-1-1'])->toEqual($second['commands-equip-icon-1-1'])
    ->and($second['commands-equip-cursor-1-1']->destination->x - $first['commands-equip-cursor-1-1']->destination->x)->toBe(4.0);
});

it('uses steady focused buttons without cursors while retaining command artwork and centered labels', function () {
  $art = new MenuRowArtwork('slot.png');
  $skins = [menuPresentationSkin(), new MenuRowSkin(menuPresentationSkin()->colors,
    artwork: ['command.selected' => $art, 'command.focus' => $art], assetRoot: $this->menuRoot)];
  $layout = new MenuRowLayout(new CanvasRectangle(21.5, 0, 401, 40));
  foreach ($skins as $skin) {
    $row = new MenuRow('ok', 'OK', kind: MenuRowKind::BUTTON, selected: true, focused: true);
    $before = MenuRowPainter::compose(450, 40, 'action', [$row], $layout, $skin, $this->menuIcons, 0);
    $after = MenuRowPainter::compose(450, 40, 'action', [$row], $layout, $skin, $this->menuIcons, 0.6);
    $layers = [...$before->textLayers, ...$before->images];
    $label = array_find($before->textLayers, fn($text) => $text->id === 'action-ok-text');
    expect($before->toArray())->toBe($after->toArray())
      ->and(array_filter($layers, fn($layer) => str_contains($layer->id, '-cursor')))->toBeEmpty()
      ->and(array_filter($layers, fn($layer) => str_contains($layer->id, '-separator')))->toBeEmpty()
      ->and(array_filter($layers, fn($layer) => str_contains($layer->id, '-selected')))->not->toBeEmpty()
      ->and(array_filter($layers, fn($layer) => str_contains($layer->id, '-focus')))->not->toBeEmpty()
      ->and($label->x + $label->bounds->width / 2)->toBe(222.0);
  }
  expect(fn() => new MenuRow('button', 'OK', [new MenuRowValue('1')], MenuRowKind::BUTTON))
    ->toThrow(InvalidArgumentException::class);
});

it('preserves steady selection when focus moves and draws focus without inventing selection', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 80));
  $rows = [new MenuRow('marked', 'First', selected: true), new MenuRow('focus', 'Second', focused: true)];
  $frame = MenuRowPainter::compose(400, 80, 'list', $rows, $layout, menuPresentationSkin(), $this->menuIcons);
  $layers = array_column($frame->textLayers, null, 'id');
  expect($layers)->toHaveKey('list-marked-selected')->not->toHaveKey('list-marked-focus-0')
    ->toHaveKey('list-focus-focus-0')->not->toHaveKey('list-focus-selected');
  expect(array_column($frame->images, 'id'))->toBe(['list-focus-cursor-1-1']);
});

it('can suppress a record cursor without suppressing its selection or focus', function () {
  $frame = MenuRowPainter::compose(400, 40, 'card',
    [new MenuRow('actor', 'Actor', selected: true, focused: true, showCursor: false)],
    new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40)), menuPresentationSkin(), $this->menuIcons);
  expect($frame->images)->toBeEmpty()
    ->and(array_column($frame->textLayers, null, 'id'))->toHaveKeys(['card-actor-selected', 'card-actor-focus-0', 'card-actor-text']);
});

it('partitions straight frame edges without overlapping alpha while preserving corner artwork', function (int $density) {
  menuPresentationPng($this->menuRoot . '/panel.png', 96, 96);
  $art = new MenuRowArtwork('panel.png', 24, 24, 24, 24, $density, [8, 8, 8, 8]);
  $box = new CanvasRectangle(10, 10, 300, 140);
  $images = array_column($art->images($this->menuRoot, 'panel', $box, 10, 20), null, 'id');
  expect($images)->toHaveCount(13)
    ->and($art->borderInsets($this->menuRoot, $box))->toBe(array_fill(0, 4, 8.0 / $density));
  foreach (['0-0', '0-2', '2-0', '2-2'] as $cell) {
    expect($images['panel-' . $cell]->layer)->toBe(20);
  }
  foreach (['0-1', '1-0', '1-2', '2-1'] as $cell) {
    $border = $images['panel-' . $cell . '-border'];
    $backing = $images['panel-' . $cell . '-backing'];
    $a = $border->clipRect;
    $b = $backing->clipRect;
    $overlap = max(0, min($a->x + $a->width, $b->x + $b->width) - max($a->x, $b->x))
      * max(0, min($a->y + $a->height, $b->y + $b->height) - max($a->y, $b->y));
    expect($border->layer)->toBe(20)->and($backing->layer)->toBe(10)
      ->and($border->destination)->toEqual($backing->destination)
      ->and($border->sourceRect)->toEqual($backing->sourceRect)
      ->and($overlap)->toEqual(0)
      ->and($a->width * $a->height + $b->width * $b->height)
      ->toEqualWithDelta($border->destination->width * $border->destination->height, 0.000001);
  }
  CanvasImagePreflight::inspect(array_values($images), $this->menuRoot);
  menuPresentationPng($this->menuRoot . '/panel.png', 9, 7);
  expect($art->borderInsets($this->menuRoot, $box))->toBe([4.0 / $density, 3.0 / $density, 4.0 / $density, 3.0 / $density]);
  CanvasImagePreflight::inspect($art->images($this->menuRoot, 'small', $box, 10, 20), $this->menuRoot);
})->with([1, 2]);

it('preserves default frame behavior and validates decorative widths as authoring intent', function () {
  $box = new CanvasRectangle(0, 0, 100, 100);
  $art = new MenuRowArtwork('slot.png', 4, 4, 4, 4);
  expect($art->borderInsets($this->menuRoot, $box))->toBeNull()
    ->and($art->images($this->menuRoot, 'panel', $box, 10, 20))->toHaveCount(9);
  foreach ([[1, 2], [-1, 0, 0, 0], [1.5, 2, 3, 4]] as $widths) {
    expect(fn() => new MenuRowArtwork('slot.png', borderWidths: $widths))->toThrow(InvalidArgumentException::class);
  }
});

it('keeps zero available equipment selectable unless the owner explicitly disables it', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40), [new MenuRowColumn(3)]);
  foreach ([false, true] as $disabled) {
    $row = new MenuRow('current', 'Current staff', [new MenuRowValue('0')], icon: WeaponType::STAFF,
      selected: true, focused: true, disabled: $disabled);
    $frame = MenuRowPainter::compose(400, 40, 'gear', [$row], $layout, menuPresentationSkin(), $this->menuIcons);
    $text = array_column($frame->textLayers, null, 'id')['gear-current-text'];
    expect(array_column($text->runs, 'text'))->toBe(['Current staff', '0'])
      ->and($text->runs[0]->foreground)->toEqual(menuPresentationSkin()->colors[$disabled ? 'disabled' : 'text'])
      ->and(count($frame->images))->toBe($disabled ? 1 : 2);
  }
});

it('keeps reduced-motion frames identical without suppressing meaning or changing configuration', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40));
  $rows = [new MenuRow('row', 'Choice', selected: true, focused: true)];
  $compose = fn($time) => MenuRowPainter::compose(400, 40, 'list', $rows, $layout, menuPresentationSkin(),
    $this->menuIcons, time: $time, reducedMotion: true);
  expect($compose(0)->toArray())->toBe($compose(0.6)->toArray())
    ->and($compose(0)->images)->toHaveCount(1);
});

it('uses explicit weapon and slot metadata and falls back only for unknown semantics', function () {
  foreach (WeaponType::cases() as $type) {
    expect($this->menuIcons->asset($type))->toBe($type === WeaponType::STAFF ? 'staff.png' : 'unknown.png');
  }
  foreach (EquipmentSlotType::cases() as $slot) {
    expect($this->menuIcons->asset($slot))->toBe($slot === EquipmentSlotType::HEAD ? 'slot.png' : 'unknown.png');
  }
  expect($this->menuIcons->asset(null))->toBeNull()
    ->and($this->menuIcons->asset('weapon.staff'))->toBe('staff.png')
    ->and($this->menuIcons->asset('custom.unregistered'))->toBe('unknown.png');
  $rows = [new MenuRow('renamed', 'A completely new name', icon: WeaponType::STAFF),
    new MenuRow('missing', 'Staff'), new MenuRow('unknown', 'Staff', icon: 'custom.unregistered')];
  $frame = MenuRowPainter::compose(500, 120, 'list', $rows, new MenuRowLayout(new CanvasRectangle(0, 0, 500, 120)),
    menuPresentationSkin(), $this->menuIcons);
  expect(array_column($frame->images, 'asset'))->toBe(['staff.png', 'unknown.png']);
});

it('requires no artwork registry and retains text and visible focus without fake glyph icons', function () {
  $row = new MenuRow('row', 'Staff', icon: WeaponType::STAFF, selected: true, focused: true);
  $frame = MenuRowPainter::compose(400, 40, 'list', [$row], new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40)), menuPresentationSkin());
  expect($frame->images)->toBe([])
    ->and(array_column($frame->textLayers, null, 'id'))->toHaveKey('list-row-focus-0')
    ->and(array_column($frame->textLayers, null, 'id')['list-row-text']->runs[0]->text)->toBe('Staff');
});

it('reads replacement file dimensions on each composition and contains both wide and tall icons', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40), [new MenuRowColumn(3)]);
  $row = new MenuRow('staff', 'Name', [new MenuRowValue('4')], icon: WeaponType::STAFF);
  $previousText = null;
  foreach ([[12, 60], [90, 15], [39, 39]] as [$width, $height]) {
    menuPresentationPng($this->menuRoot . '/staff.png', $width, $height);
    $frame = MenuRowPainter::compose(400, 40, 'list', [$row], $layout, menuPresentationSkin(), $this->menuIcons);
    $image = $frame->images[0];
    expect($image->destination->width / $image->destination->height)->toEqualWithDelta($width / $height, 0.000001)
      ->and(max($image->destination->width, $image->destination->height))->toEqualWithDelta(24.0, 0.000001)
      ->and($image->destination->x + $image->destination->width / 2)->toEqualWithDelta(42.0, 0.000001)
      ->and($image->destination->y + $image->destination->height / 2)->toEqualWithDelta(20.0, 0.000001);
    CanvasImagePreflight::inspect($frame->images, $this->menuRoot);
    if ($previousText !== null) { expect($frame->textLayers)->toEqual($previousText); }
    $previousText = $frame->textLayers;
  }
});

it('diagnoses unavailable icons while retaining healthy icons labels and the configured unknown fallback', function (string $asset, bool $unknown) {
  file_put_contents($this->menuRoot . '/bad.png', 'not a PNG');
  menuPresentationPng($this->menuRoot . '/wrong.jpg', 1, 1);
  $bindings = ['weapon.staff' => $asset, 'slot.head' => 'slot.png'];
  if ($unknown) { $bindings['unknown'] = 'unknown.png'; }
  $registry = new MenuIconRegistry($this->menuRoot, $bindings);
  expect(file_exists($this->menuRoot . '/warning.log'))->toBeFalse();
  $frame = MenuRowPainter::compose(400, 80, 'list', [new MenuRow('row', 'Staff', icon: WeaponType::STAFF),
    new MenuRow('head', 'Head', icon: EquipmentSlotType::HEAD)],
    new MenuRowLayout(new CanvasRectangle(0, 0, 400, 80)), menuPresentationSkin(), $registry);
  expect(array_column($frame->images, 'asset'))->toBe($unknown ? ['unknown.png', 'slot.png'] : ['slot.png'])
    ->and(array_column($frame->textLayers, null, 'id')['list-row-text']->runs[0]->text)->toBe('Staff')
    ->and(file_get_contents($this->menuRoot . '/warning.log'))->toContain($asset);
})->with(['missing.png', 'bad.png', 'wrong.jpg'])->with([false, true]);

it('rejects truncation and partial rows while preserving the complete input for owner relayout', function () {
  $label = str_repeat('Long name ', 30);
  $row = new MenuRow('long', $label, [new MenuRowValue('123456789')]);
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 79), [new MenuRowColumn(3)]);
  expect(fn() => MenuRowPainter::compose(400, 79, 'list', [$row], $layout, menuPresentationSkin()))
    ->toThrow(InvalidArgumentException::class, 'must not be silently truncated')
    ->and($row->label)->toBe($label)->and($row->values[0]->text)->toBe('123456789');
  expect(fn() => MenuRowPainter::compose(400, 79, 'list', [new MenuRow('a', 'A'), new MenuRow('b', 'B')],
    $layout, menuPresentationSkin()))->toThrow(InvalidArgumentException::class, 'whole-row visible subset');
  expect(fn() => MenuRowPainter::compose(400, 79, 'list', [new MenuRow('value', 'HP', [new MenuRowValue('1000')])],
    $layout, menuPresentationSkin()))->toThrow(InvalidArgumentException::class, 'wider columns');
});

it('fits flat paint and text at fractional origins and odd-sized canvas edges', function () {
  foreach ([513, 701, 1100] as $width) {
    $layout = new MenuRowLayout(new CanvasRectangle(0, 0.5, $width, 40.5));
    $frame = MenuRowPainter::compose($width, 41, 'edge', [new MenuRow('row', 'Value', selected: true, focused: true)],
      $layout, menuPresentationSkin());
    foreach ($frame->textLayers as $text) {
      $text->bounds->assertWithin($width, 41);
      expect($text->clipRect->x)->toBeGreaterThanOrEqual(0.0)
        ->and($text->clipRect->y)->toBeGreaterThanOrEqual(0.5)
        ->and($text->clipRect->y + $text->clipRect->height)->toBeLessThanOrEqual(40.5);
    }
  }
});

it('leaves layer capacity for source-sized Items rows targets commands and summaries', function () {
  $skin = menuPresentationSkin();
  $frames = [];
  foreach ([['items', 12, 0, 0, 680, true], ['targets', 10, 700, 0, 380, false],
    ['summary', 2, 700, 440, 380, false]] as [$id, $count, $x, $y, $width, $selected]) {
    $rows = array_map(fn($i) => new MenuRow((string)$i, 'Label', [new MenuRowValue((string)$i)],
      selected: $selected && $i === 0, focused: $selected && $i === 0), range(0, $count - 1));
    $frames[] = MenuRowPainter::compose(1100, 700, $id, $rows,
      new MenuRowLayout(new CanvasRectangle($x, $y, $width, $count * 40), [new MenuRowColumn(6)]), $skin, $this->menuIcons);
  }
  $frames[] = MenuRowPainter::compose(1100, 700, 'commands', array_map(fn($i) => new MenuRow((string)$i,
    'Command', kind: MenuRowKind::COMMAND), range(0, 3)), new MenuRowLayout(new CanvasRectangle(0, 520, 300, 160)), $skin);
  $frame = new PresentationCanvas(1100, 700, array_merge(...array_column($frames, 'images')),
    textLayers: array_merge(...array_column($frames, 'textLayers')));
  expect(count($frame->textLayers))->toBeLessThanOrEqual(60);
});

it('validates row identity data columns and finite geometry at the boundary', function () {
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 80));
  foreach ([NAN, INF, -1] as $time) {
    expect(fn() => MenuRowPainter::compose(400, 80, 'list', [], $layout, menuPresentationSkin(), time: $time))
      ->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => MenuRowPainter::compose(400, 80, 'list', [new MenuRow('same', 'One'), new MenuRow('same', 'Two')],
    $layout, menuPresentationSkin()))->toThrow(InvalidArgumentException::class, 'unique stable IDs');
  expect(fn() => new MenuRow('id', "Line\nBreak"))->toThrow(InvalidArgumentException::class);
  expect(fn() => new MenuRow('id', 'Command', [new MenuRowValue('1')], MenuRowKind::COMMAND))->toThrow(InvalidArgumentException::class);
  expect(fn() => new MenuRowLayout(new CanvasRectangle(0, 0, 80, 40), [new MenuRowColumn(6)])
    ->assertFits(new MenuRowMetrics()))->toThrow(InvalidArgumentException::class);
  expect(fn() => new MenuRowColumn(0))->toThrow(InvalidArgumentException::class);
  expect(new MenuIconRegistry($this->menuRoot, [])->asset('unregistered'))->toBeNull();
  expect(fn() => new MenuIconRegistry($this->menuRoot, ['unknown' => '../outside.png']))->toThrow(InvalidArgumentException::class);
  expect(fn() => new MenuRowSkin([]))->toThrow(InvalidArgumentException::class);
});

it('detaches row columns values and palette from caller-owned reference aliases', function () {
  $value = new MenuRowValue('1');
  $column = new MenuRowColumn(3);
  $color = PresentationColor::rgb(1, 2, 3);
  $row = new MenuRow('id', 'Quantity', [&$value]);
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40), [&$column]);
  $colors = menuPresentationSkin()->colors;
  $colors['text'] = &$color;
  $skin = new MenuRowSkin($colors);
  $value = new MenuRowValue('2');
  $column = new MenuRowColumn(9);
  $color = PresentationColor::rgb(4, 5, 6);
  expect($row->values[0]->text)->toBe('1')->and($layout->columns[0]->cells)->toBe(3)
    ->and($skin->colors['text']->toArray())->toBe(PresentationColor::rgb(1, 2, 3)->toArray());
});

it('uses the existing reduced-motion preference by default without mutating it', function () {
  $previous = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  try {
    $settings = new PlaySettings(['accessibility' => ['reducedMotion' => true]]);
    ConfigStore::put(ProjectConfig::class, $settings);
    $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40));
    $rows = [new MenuRow('row', 'Choice', selected: true, focused: true)];
    $frame = fn($time) => MenuRowPainter::compose(400, 40, 'list', $rows, $layout, menuPresentationSkin(), $this->menuIcons, time: $time);
    expect($frame(0)->toArray())->toBe($frame(0.6)->toArray())
      ->and($settings->get('accessibility.reducedMotion'))->toBeTrue();
  } finally {
    foreach ($previous as $name => $value) { new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value); }
  }
});

it('renders the same records and commands with a second art theme without changing owner state', function () {
  foreach (['paper', 'mark', 'blocked', 'ring', 'button', 'button-mark', 'button-ring'] as $name) {
    menuPresentationPng($this->menuRoot . '/' . $name . '.png', 60, 30);
  }
  menuPresentationPng($this->menuRoot . '/wide-icon.png', 72, 24);
  menuPresentationPng($this->menuRoot . '/tall-cursor.png', 9, 27);
  $metrics = new MenuRowMetrics(padding: 38, gapCells: 2, iconWidth: 36, iconHeight: 18,
    cursorWidth: 9, cursorHeight: 27, cursorInset: 5, cursorTravel: 12, cursorPeriod: 2,
    separatorWidth: 2, recordSeparatorOpacity: 0.6, headingSeparatorOpacity: 0.9, accentWidth: 5, focusWidth: 3);
  $colors = array_combine(MenuRowSkin::COLORS, array_map(fn($i) => PresentationColor::rgb(180 - 15 * $i, 50 + 20 * $i, 30), range(0, 5)));
  $theme = new MenuRowSkin($colors, $metrics, [
    'normal' => new MenuRowArtwork('paper.png', 6, 4, 6, 4),
    'selected' => new MenuRowArtwork('mark.png', 6, 4, 6, 4),
    'disabled' => new MenuRowArtwork('blocked.png'),
    'focus' => new MenuRowArtwork('ring.png', 8, 5, 8, 5),
    'command.normal' => new MenuRowArtwork('button.png', 6, 4, 6, 4),
    'command.selected' => new MenuRowArtwork('button-mark.png', 6, 4, 6, 4),
    'command.focus' => new MenuRowArtwork('button-ring.png', 8, 5, 8, 5),
  ], $this->menuRoot);
  $registry = new MenuIconRegistry($this->menuRoot, ['weapon.staff' => 'wide-icon.png', 'unknown' => 'unknown.png'], 'tall-cursor.png');
  $rows = [
    new MenuRow('current', 'Renamed gear', [new MenuRowValue('0')], icon: WeaponType::STAFF, selected: true),
    new MenuRow('candidate', 'Other gear', [new MenuRowValue('12')], icon: WeaponType::STAFF, focused: true),
    new MenuRow('blocked', 'Unavailable', [new MenuRowValue('7')], disabled: true),
    new MenuRow('equip-action', 'Equip', kind: MenuRowKind::COMMAND, icon: WeaponType::STAFF, selected: true, focused: true),
  ];
  $before = serialize($rows);
  $layout = new MenuRowLayout(new CanvasRectangle(20, 10, 600, 192), [new MenuRowColumn(5)], rowHeight: 48);
  $first = MenuRowPainter::compose(640, 220, 'menu', $rows, $layout, menuPresentationSkin(), $this->menuIcons);
  $second = MenuRowPainter::compose(640, 220, 'menu', $rows, $layout, $theme, $registry);
  $later = MenuRowPainter::compose(640, 220, 'menu', $rows, $layout, $theme, $registry, time: 1);
  $a = array_column($first->textLayers, null, 'id');
  $b = array_column($second->textLayers, null, 'id');
  $pictures = array_column($second->images, null, 'id');
  foreach ($rows as $row) {
    expect(array_column($b['menu-' . $row->id . '-text']->runs, 'text'))
      ->toBe(array_column($a['menu-' . $row->id . '-text']->runs, 'text'));
  }
  expect(serialize($rows))->toBe($before)
    ->and([$rows[0]->selected, $rows[0]->focused, $rows[0]->disabled, $rows[0]->values[0]->text])->toBe([true, false, false, '0'])
    ->and([$rows[1]->selected, $rows[1]->focused])->toBe([false, true])
    ->and($rows[3]->id)->toBe('equip-action')
    ->and($b['menu-current-text']->runs[0]->foreground)->toEqual($colors['text'])
    ->and($b['menu-blocked-text']->runs[0]->foreground)->toEqual($colors['disabled'])
    ->and($b)->not->toHaveKey('menu-current-selected')->not->toHaveKey('menu-current-accent')
    ->not->toHaveKey('menu-candidate-focus-0')->not->toHaveKey('menu-equip-action-separator')
    ->and($b['menu-current-separator']->opacity)->toBe(0.6)
    ->and($b['menu-current-separator']->clipRect->height)->toBe(2.0);
  expect($pictures)->toHaveKey('menu-current-selected-1-1')->toHaveKey('menu-candidate-focus-1-1')
    ->not->toHaveKey('menu-candidate-selected-1-1')->not->toHaveKey('menu-current-cursor-1-1')
    ->toHaveKey('menu-blocked-disabled-1-1')
    ->and($pictures['menu-equip-action-normal-1-1']->asset)->toBe('button.png')
    ->and($pictures['menu-equip-action-selected-1-1']->asset)->toBe('button-mark.png')
    ->and($pictures['menu-equip-action-focus-1-1']->asset)->toBe('button-ring.png')
    ->and($pictures['menu-current-icon-1-1']->destination->width)->toBe(36.0)
    ->and($pictures['menu-current-icon-1-1']->destination->height)->toBe(12.0)
    ->and($pictures['menu-candidate-cursor-1-1']->destination->width)->toBe(9.0)
    ->and($pictures['menu-candidate-cursor-1-1']->destination->height)->toBe(27.0);
  foreach ([$a, $b] as $text) {
    $label = $text['menu-equip-action-text'];
    expect($label->x + $label->bounds->width / 2)->toBe(320.0);
  }
  $moving = array_column($later->images, null, 'id');
  expect($later->textLayers)->toEqual($second->textLayers)
    ->and($moving['menu-candidate-cursor-1-1']->destination->x - $pictures['menu-candidate-cursor-1-1']->destination->x)->toBe(12.0);
  expect(MenuRowPainter::compose(640, 220, 'menu', $rows, $layout, $theme, $registry, reducedMotion: true)->toArray())
    ->toBe(MenuRowPainter::compose(640, 220, 'menu', $rows, $layout, $theme, $registry, time: 1, reducedMotion: true)->toArray());
  CanvasImagePreflight::inspect($second->images, $this->menuRoot);
});

it('uses configurable primitive accents focus and compact spacing when state artwork is absent', function () {
  $metrics = new MenuRowMetrics(padding: 4, gapCells: 0, iconWidth: 8, iconHeight: 8,
    cursorWidth: 2, cursorHeight: 6, cursorInset: 1, cursorTravel: 0, cursorPeriod: 3,
    separatorWidth: 0.5, recordSeparatorOpacity: 0.4, headingSeparatorOpacity: 0.8, accentWidth: 4, focusWidth: 2);
  $skin = new MenuRowSkin(menuPresentationSkin()->colors, $metrics);
  $frame = MenuRowPainter::compose(120, 40, 'compact', [
    new MenuRow('heading', 'Value', kind: MenuRowKind::HEADING, selected: true, focused: true),
    new MenuRow('row', 'HP'),
  ], new MenuRowLayout(new CanvasRectangle(0, 0, 120, 40), rowHeight: 20, cellWidth: 6, cellHeight: 12), $skin);
  $text = array_column($frame->textLayers, null, 'id');
  expect($text['compact-heading-accent']->clipRect->width)->toBe(4.0)
    ->and($text['compact-heading-focus-0']->clipRect->height)->toBe(2.0)
    ->and($text['compact-heading-separator']->opacity)->toBe(0.8)
    ->and($text['compact-row-separator']->opacity)->toBe(0.4)
    ->and($text['compact-row-separator']->clipRect->height)->toBe(0.5);
});

it('keeps state artwork replaceable and reconciles cuts without stored source dimensions', function () {
  menuPresentationPng($this->menuRoot . '/state.png', 50, 30);
  $theme = new MenuRowSkin(menuPresentationSkin()->colors, artwork: [
    'selected' => new MenuRowArtwork('state.png', 8, 6, 8, 6),
  ], assetRoot: $this->menuRoot);
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40));
  $rows = [new MenuRow('row', 'Stable', selected: true)];
  $originalText = null;
  foreach ([[50, 30], [9, 7], [81, 49]] as [$width, $height]) {
    menuPresentationPng($this->menuRoot . '/state.png', $width, $height);
    $frame = MenuRowPainter::compose(400, 40, 'menu', $rows, $layout, $theme);
    CanvasImagePreflight::inspect($frame->images, $this->menuRoot);
    foreach ($frame->images as $image) {
      expect($image->sourceRect->x + $image->sourceRect->width)->toBeLessThanOrEqual($width)
        ->and($image->sourceRect->y + $image->sourceRect->height)->toBeLessThanOrEqual($height)
        ->and($image->clipRect->toArray())->toBe($layout->viewport->toArray());
    }
    expect(array_sum(array_map(fn($image) => $image->destination->width * $image->destination->height, $frame->images)))
      ->toEqualWithDelta($layout->viewport->width * $layout->viewport->height, 0.000001);
    if ($originalText !== null) { expect($frame->textLayers)->toEqual($originalText); }
    $originalText = $frame->textLayers;
  }
  expect($theme->artwork['selected']->left)->toBe(8);
});

it('rejects invalid theme geometry and uses a primitive fallback for unavailable row artwork', function () {
  foreach ([fn() => new MenuRowMetrics(cursorPeriod: 0), fn() => new MenuRowMetrics(iconWidth: INF),
    fn() => new MenuRowMetrics(recordSeparatorOpacity: 2), fn() => new MenuRowMetrics(padding: -1),
    fn() => new MenuRowArtwork('../outside.png'), fn() => new MenuRowArtwork('art.png', left: -1)] as $invalid) {
    expect($invalid)->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new MenuRowSkin(menuPresentationSkin()->colors, artwork: ['focus' => new MenuRowArtwork('ring.png')]))
    ->toThrow(InvalidArgumentException::class);
  $rows = [new MenuRow('row', 'Label', focused: true)];
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 40));
  $overlap = new MenuRowSkin(menuPresentationSkin()->colors, new MenuRowMetrics(padding: 20));
  expect(fn() => MenuRowPainter::compose(400, 40, 'list', $rows, $layout, $overlap, $this->menuIcons))
    ->toThrow(InvalidArgumentException::class, 'separate leading gutter');
  $missing = new MenuRowSkin(menuPresentationSkin()->colors, artwork: ['normal' => new MenuRowArtwork('missing.png')], assetRoot: $this->menuRoot);
  $frame = MenuRowPainter::compose(400, 40, 'list', $rows, $layout, $missing);
  expect($frame)->toEqual(MenuRowPainter::compose(400, 40, 'list', $rows, $layout, menuPresentationSkin()))
    ->and(file_get_contents($this->menuRoot . '/warning.log'))->toContain('missing.png');
});
