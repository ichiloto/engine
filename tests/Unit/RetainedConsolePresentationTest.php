<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\RetainedConsolePresentation;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'usingAlternateScreen' => false, 'terminalOutputEnabled' => false, 'output' => null,
    'terminalOutputStream' => null, 'overlays' => [], 'presentationBaseCells' => [],
    'retainedWorldPresentation' => false, 'retainedPresentation' => null,
    'buffer' => [], 'layerCells' => [], 'layerPriorities' => [], 'trackLayers' => false,
    'activeLayer' => null, 'activeLayerPriority' => 0,
    'frameRows' => [], 'recomposeRepaintRows' => []] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::setLayerTracking(true);
  Console::syncDimensions(12, 5);
  $this->retainedLayers = [];
  $this->retainedOrder = [];
  ob_start();
});

afterEach(function () {
  expect(ob_get_clean())->toBe('');
  foreach ($this->consoleState as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
});

function applyConsoleRowChanges(object $test, ConsolePresentationChanges $changes): ConsolePresentationSnapshot
{
  if ($changes->reset) { $test->retainedLayers = []; }
  foreach ($changes->removedIds as $id) { unset($test->retainedLayers[$id]); }
  foreach ($changes->layers as $layer) {
    $test->retainedLayers[$layer['id']]['layer'] = $layer['layer'];
    foreach ($layer['rows'] as $row) {
      if ($row['runs'] === []) { unset($test->retainedLayers[$layer['id']]['rows'][$row['row']]); }
      else { $test->retainedLayers[$layer['id']]['rows'][$row['row']] = $row['runs']; }
    }
  }
  if ($changes->order !== null) { $test->retainedOrder = $changes->order; }
  $layers = [];
  foreach ($test->retainedOrder as $id) {
    $layer = $test->retainedLayers[$id];
    $rows = $layer['rows'] ?? [];
    ksort($rows);
    $layers[] = new PresentationTextLayer((string)$id, $layer['layer'], array_merge([], ...array_values($rows)));
  }
  return new ConsolePresentationSnapshot($changes->width, $changes->height, $layers);
}

function countConsoleComposedRows(): int
{
  $tracker = new ReflectionProperty(Console::class, 'retainedPresentation')->getValue();
  return $tracker === null ? 0 : new ReflectionProperty(RetainedConsolePresentation::class, 'composedRows')->getValue($tracker);
}

it('replays only changed rows with stable metadata removals and unchanged ordering', function () {
  $first = Console::getRetainedPresentationChanges();
  expect($first->reset)->toBeTrue()->and($first->order)->toBe(['world']);
  expect(applyConsoleRowChanges($this, $first))->toEqual(Console::presentationSnapshot());
  Console::withLayer('npc:one', fn() => Console::write("\e[31mN", 2, 1), 100);
  $changes = Console::getRetainedPresentationChanges();
  expect(array_column($changes->layers, 'id'))->toBe(['npc:one'])
    ->and(array_column($changes->layers[0]['rows'], 'row'))->toBe([1]);
  expect(applyConsoleRowChanges($this, $changes))->toEqual(Console::presentationSnapshot());
  $count = countConsoleComposedRows();
  $same = Console::getRetainedPresentationChanges();
  expect($same->layers)->toBe([])->and($same->removedIds)->toBe([])->and($same->order)->toBeNull()
    ->and(countConsoleComposedRows())->toBe($count);
  Console::withLayer('npc:one', fn() => Console::write("\e[31mN", 2, 1), 100);
  expect(Console::getRetainedPresentationChanges()->layers)->toBe([])
    ->and(countConsoleComposedRows())->toBe($count);
  Console::removeLayer('npc:one', repaint: false);
  $removed = Console::getRetainedPresentationChanges();
  expect($removed->removedIds)->toBe(['npc:one']);
  expect(applyConsoleRowChanges($this, $removed))->toEqual(Console::presentationSnapshot());
});

it('keeps complete layer order for equal priorities and metadata-only priority changes', function () {
  Console::withLayer('a', fn() => Console::write('A', 1, 1), 100);
  Console::withLayer('b', fn() => Console::write('B', 1, 1), 100);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::withLayer('a', static fn() => null, 100);
  $reordered = Console::getRetainedPresentationChanges();
  expect($reordered->order)->toBe(['world', 'b', 'a']);
  expect(applyConsoleRowChanges($this, $reordered))->toEqual(Console::presentationSnapshot());
  Console::withLayer('a', static fn() => null, 200);
  $changed = Console::getRetainedPresentationChanges();
  expect($changed->layers)->toBe([['id' => 'a', 'layer' => 200, 'rows' => []]]);
  expect(applyConsoleRowChanges($this, $changed))->toEqual(Console::presentationSnapshot());
});

it('replays wide glyph overlap overlay priority removal and sprite exclusion changes', function () {
  Console::write("\e[34m界\e[0mAB", 0, 1);
  Console::withLayer('npc', fn() => Console::write('n', 1, 1), 100);
  Console::replaceOverlay('notice', ['界'], 1, 1, 1000);
  Console::withLayer('hud', fn() => Console::write('H', 2, 1), 2000);
  foreach ([[], ['npc'], ['hud'], ['notice'], [], ['npc', 'hud']] as $excluded) {
    expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges($excluded)))
      ->toEqual(Console::presentationSnapshot($excluded));
  }
  Console::removeLayer('hud');
  expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges()))->toEqual(Console::presentationSnapshot());
  Console::replaceOverlay('notice', ['Z'], 5, 3, 1000);
  $moved = Console::getRetainedPresentationChanges();
  $notice = array_values(array_filter($moved->layers, fn($layer) => $layer['id'] === 'notice'))[0];
  expect(array_column($notice['rows'], 'row'))->toBe([1, 3])->and($notice['rows'][0]['runs'])->toBe([]);
  expect(applyConsoleRowChanges($this, $moved))->toEqual(Console::presentationSnapshot());
  Console::removeOverlay('notice');
  expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges()))->toEqual(Console::presentationSnapshot());
});

it('excludes retained map contributions and older underlays but preserves later screen text', function () {
  Console::write('base', 0, 0);
  Console::withLayer('older', fn() => Console::write('O', 1, 0), 0);
  Console::withLayer('map', fn() => Console::write('....', 0, 0), 0);
  Console::withLayer('npc', fn() => Console::write('N', 1, 0), 100);
  Console::withLayer('hud', fn() => Console::write('H', 2, 0), 1000);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  $changes = Console::getRetainedPresentationChanges(['npc'], excludedWorldLayers: ['map']);
  $state = applyConsoleRowChanges($this, $changes);
  expect(array_column($state->textLayers, 'id'))->toBe(['world', 'hud'])
    ->and($state->textLayers[0]->runs[0]->column)->toBe(4)
    ->and($state->textLayers[1]->runs[0]->text)->toBe('H');
  expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges()))->toEqual(Console::presentationSnapshot());
});

it('omits only synthetic base blanks retaining authored spaces styles and opaque UI', function () {
  Console::setRetainedWorldPresentation(true);
  $empty = Console::getRetainedPresentationChanges();
  expect($empty->layers)->toBe([['id' => 'world', 'layer' => 0, 'rows' => []]])
    ->and(new ReflectionProperty(Console::class, 'buffer')->getValue())->toBe([]);
  Console::write(' ', 3, 1);
  Console::write("\e[44m ", 6, 1);
  Console::withLayer('ui', fn() => Console::write('   ', 2, 3), 1000);
  $state = applyConsoleRowChanges($this, Console::getRetainedPresentationChanges(reset: true));
  expect($state->textLayers[0]->runs)->toHaveCount(2)
    ->and($state->textLayers[0]->runs[0]->column)->toBe(3)
    ->and($state->textLayers[0]->runs[0]->text)->toBe(' ')
    ->and($state->textLayers[0]->runs[1]->background?->toArray())->toBe(['kind' => 'ansi16', 'index' => 4])
    ->and($state->textLayers[1]->runs[0]->text)->toBe('   ')
    ->and(Console::snapshot()->rows)->toBe(array_fill(0, 5, str_repeat(' ', 12)))
    ->and(Console::getBuffer())->toHaveCount(5);
  Console::setRetainedWorldPresentation(false);
  expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges()))->toEqual(Console::presentationSnapshot());
});

it('restores retained map cells across wide world glyphs without erasing UI or overlays', function () {
  Console::setRetainedWorldPresentation(true);
  Console::write('界X', 0, 1);
  Console::withLayer('npc', fn() => Console::write('界N', 0, 1), 100);
  Console::withLayer('ui', fn() => Console::write('H', 1, 1), 1000);
  Console::replaceOverlay('notice', ['!'], 2, 1, 2000);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::removeWorldCellContributions(1, 1);
  $state = applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  expect($state->textLayers[0]->runs[0]->column)->toBe(2)
    ->and($state->textLayers[0]->runs[0]->text)->toBe('X')
    ->and(Console::snapshot()->rows[1])->toStartWith(' H!');
  Console::removeWorldCellContributions(-10, 1, 30);
  $state = applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  expect(array_column($state->textLayers, 'id'))->toBe(['world', 'ui', 'notice'])
    ->and($state->textLayers[0]->runs)->toBe([])
    ->and(Console::snapshot()->rows[1])->toStartWith(' H!');
  Console::removeLayer('ui');
  Console::removeOverlay('notice');
  expect(Console::snapshot()->rows[1])->toBe(str_repeat(' ', 12));
});

it('moves removes and restores entity fallbacks without leaving old glyphs or opaque spaces', function () {
  Console::setRetainedWorldPresentation(true);
  Console::withLayer('npc:stable', fn() => Console::write('N', 1, 1), 100);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::removeWorldCellContributions(1, 1);
  Console::withLayer('npc:stable', fn() => Console::write('N', 3, 2), 100);
  $state = applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  expect($state->textLayers[0]->runs)->toBe([])->and($state->textLayers[1]->runs)->toHaveCount(1)
    ->and($state->textLayers[1]->runs[0]->row)->toBe(2)->and($state->textLayers[1]->runs[0]->column)->toBe(3);
  expect(Console::getRetainedPresentationChanges(['npc:stable'])->removedIds)->toBe(['npc:stable']);
  expect(Console::getRetainedPresentationChanges()->layers[0]['id'])->toBe('npc:stable');
  Console::removeLayer('npc:stable', repaint: false);
  expect(Console::getRetainedPresentationChanges()->removedIds)->toBe(['npc:stable']);
});

it('keeps erase inert without retained world mode or with terminal output enabled', function () {
  Console::write('text', 0, 0);
  $before = Console::snapshot();
  Console::removeWorldCellContributions(0, 0, 4);
  expect(Console::snapshot())->toEqual($before);
  Console::setRetainedWorldPresentation(true);
  Console::setTerminalOutputEnabled(true);
  expect(Console::isRetainedWorldPresentation())->toBeFalse();
  Console::removeWorldCellContributions(0, 0, 4);
  expect(Console::snapshot())->toEqual($before);
  Console::setTerminalOutputEnabled(false);
});

it('uses sparse recompose for unchanged frames independent of screen height', function (int $height) {
  Console::syncDimensions(200, $height);
  Console::setRetainedWorldPresentation(true);
  $draw = static fn() => Console::withLayer('hud', fn() => Console::write('HUD', 10, 2), 1000);
  Console::recomposeFrame($draw);
  Console::getRetainedPresentationChanges();
  $count = countConsoleComposedRows();
  for ($frame = 0; $frame < 10; $frame++) {
    Console::recomposeFrame($draw);
    $same = Console::getRetainedPresentationChanges();
    expect($same->layers)->toBe([])->and($same->order)->toBeNull();
  }
  expect(countConsoleComposedRows())->toBe($count)->and($count)->toBe(1)
    ->and(array_keys(new ReflectionProperty(Console::class, 'buffer')->getValue()))->toBe([2]);
  Console::recomposeFrame(static fn() => null);
  expect(Console::getRetainedPresentationChanges()->removedIds)->toBe(['hud'])
    ->and(new ReflectionProperty(Console::class, 'buffer')->getValue())->toBe([]);
})->with([50, 200]);

it('rolls back failed sparse composition including pending changes and overlays', function () {
  Console::setRetainedWorldPresentation(true);
  Console::write('old', 0, 0);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::write('pending', 0, 2);
  $before = Console::presentationSnapshot();
  expect(fn() => Console::recomposeFrame(function () {
    Console::withLayer('temporary', fn() => Console::write('bad', 0, 0), 100);
    Console::replaceOverlay('new', ['bad'], 0, 0, 1000);
    throw new RuntimeException('rollback');
  }))->toThrow(RuntimeException::class, 'rollback');
  expect(Console::presentationSnapshot())->toEqual($before)->and(Console::isComposing())->toBeFalse();
  $changes = Console::getRetainedPresentationChanges();
  expect(array_column($changes->layers[0]['rows'], 'row'))->toBe([2]);
  expect(fn() => Console::recomposeFrame(fn() => Console::beginFrame()))->toThrow(RuntimeException::class, 'unbalanced');
  expect(Console::presentationSnapshot())->toEqual($before);
});

it('preserves overlays during clear and resets current state after resize or rejected delivery', function () {
  Console::withLayer('old', fn() => Console::write('old', 0, 1), 100);
  Console::replaceOverlay('notice', ['stay'], 0, 0, 2000);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::clear();
  $clear = Console::getRetainedPresentationChanges();
  expect($clear->removedIds)->toBe(['old']);
  expect(applyConsoleRowChanges($this, $clear))->toEqual(Console::presentationSnapshot());
  Console::write('not delivered', 0, 2);
  Console::getRetainedPresentationChanges();
  $reset = Console::getRetainedPresentationChanges(reset: true);
  expect($reset->reset)->toBeTrue();
  expect(applyConsoleRowChanges($this, $reset))->toEqual(Console::presentationSnapshot());
  Console::syncDimensions(8, 3);
  $resized = Console::getRetainedPresentationChanges();
  expect($resized->reset)->toBeTrue()->and($resized->width)->toBe(8)->and($resized->height)->toBe(3);
  expect(applyConsoleRowChanges($this, $resized))->toEqual(Console::presentationSnapshot());
});

it('does not consume dirty rows when style validation fails', function () {
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::write("\e[38;5;256mX", 0, 1);
  $count = countConsoleComposedRows();
  expect(fn() => Console::getRetainedPresentationChanges())->toThrow(InvalidArgumentException::class);
  expect(countConsoleComposedRows())->toBe($count);
  Console::write('fixed', 0, 1);
  expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges()))->toEqual(Console::presentationSnapshot());
});

it('validates detached row updates and preserves numeric-string stable IDs', function () {
  Console::withLayer('12', fn() => Console::write('X', 0, 0), 100);
  expect(Console::getRetainedPresentationChanges()->order)->toBe(['world', '12']);
  Console::removeLayer('12');
  expect(Console::getRetainedPresentationChanges()->removedIds)->toBe(['12']);
  $run = new PresentationTextRun(1, 1, 'X');
  $changes = new ConsolePresentationChanges(12, 5, false, [
    ['id' => 'layer', 'layer' => 100, 'rows' => [['row' => 1, 'runs' => [&$run]]]],
  ]);
  $run = new PresentationTextRun(1, 1, 'Y');
  expect($changes->layers[0]['rows'][0]['runs'][0]->text)->toBe('X');
  expect(fn() => new ConsolePresentationChanges(12, 5, false, [
    ['id' => 'layer', 'layer' => 100, 'rows' => [['row' => 2, 'runs' => [$run]]]],
  ]))->toThrow(InvalidArgumentException::class);
});

it('matches the reference through a deterministic mixed incremental lifecycle', function () {
  $random = new Random\Randomizer(new Random\Engine\Mt19937(917));
  $glyphs = ['A', ' ', "\e[31mZ", '界', "e\u{301}", '👨‍👩‍👧‍👦'];
  $ids = ['npc', 'prop', 'hud'];
  for ($step = 0; $step < 180; $step++) {
    $x = $random->getInt(0, 10);
    $y = $random->getInt(0, 4);
    $id = $ids[$random->getInt(0, 2)];
    $glyph = $glyphs[$random->getInt(0, count($glyphs) - 1)];
    switch ($random->getInt(0, 7)) {
      case 0: Console::write($glyph, $x, $y); break;
      case 1:
      case 2: Console::withLayer($id, fn() => Console::write($glyph, $x, $y), $id === 'hud' ? 1500 : 100); break;
      case 3: Console::removeLayer($id, repaint: false); break;
      case 4: Console::replaceOverlay('notice', [$glyph], $x, $y, 1000); break;
      case 5: Console::removeOverlay('notice'); break;
      case 6: Console::recomposeFrame(fn() => Console::withLayer($id, fn() => Console::write($glyph, $x, $y), 100)); break;
      case 7: Console::withLayer($id, fn() => Console::write($glyph, $x, $y), 1500); break;
    }
    $excluded = $step % 3 === 0 ? ['npc'] : [];
    expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges($excluded)))
      ->toEqual(Console::presentationSnapshot($excluded), "Reference mismatch at step {$step}");
  }
});

it('preserves overlay updates inside sparse recompose including priority-only changes', function () {
  Console::setRetainedWorldPresentation(true);
  Console::replaceOverlay('notice', ['old'], 2, 1, 1000);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  Console::recomposeFrame(fn() => Console::replaceOverlay('notice', ['new'], 2, 3, 1000));
  $changes = Console::getRetainedPresentationChanges();
  expect(array_column($changes->layers[0]['rows'], 'row'))->toBe([1, 3]);
  applyConsoleRowChanges($this, $changes);
  Console::recomposeFrame(fn() => Console::replaceOverlay('notice', ['new'], 2, 3, 1200));
  expect(Console::getRetainedPresentationChanges()->layers)->toBe([['id' => 'notice', 'layer' => 1200, 'rows' => []]]);
  Console::recomposeFrame(fn() => Console::removeOverlay('notice'));
  expect(Console::getRetainedPresentationChanges()->removedIds)->toBe(['notice']);
});

it('does not let removed map underlays suppress an overlay', function () {
  Console::withLayer('older', fn() => Console::write('H', 1, 1), 2000);
  Console::withLayer('map', fn() => Console::write('.', 1, 1), 0);
  Console::replaceOverlay('notice', ['!'], 1, 1, 1000);
  applyConsoleRowChanges($this, Console::getRetainedPresentationChanges());
  $state = applyConsoleRowChanges($this, Console::getRetainedPresentationChanges(excludedWorldLayers: ['map']));
  expect(array_column($state->textLayers, 'id'))->toBe(['world', 'notice'])
    ->and($state->textLayers[1]->runs[0]->text)->toBe('!');
});

it('rejects reading while a frame is incomplete without consuming prior changes', function () {
  Console::write('pending', 0, 1);
  Console::beginFrame();
  expect(fn() => Console::getRetainedPresentationChanges())->toThrow(RuntimeException::class);
  Console::endFrame();
  expect(applyConsoleRowChanges($this, Console::getRetainedPresentationChanges()))->toEqual(Console::presentationSnapshot());
});

it('retains previously authored spaces when tracking starts and materializes lazy rows when it stops', function () {
  Console::setLayerTracking(false);
  Console::write(' ', 3, 2);
  Console::write('B', 5, 2);
  Console::withLayer('flattened', fn() => Console::write('F', 8, 2), 100);
  Console::setLayerTracking(true);
  Console::setRetainedWorldPresentation(true);
  $changes = Console::getRetainedPresentationChanges();
  expect(array_column($changes->layers, 'id'))->toBe(['world'])
    ->and(array_column($changes->layers[0]['rows'][0]['runs'], 'text'))->toBe([' ', 'B', 'F']);
  Console::recomposeFrame(fn() => Console::write('X', 1, 2));
  expect(array_keys(new ReflectionProperty(Console::class, 'buffer')->getValue()))->toBe([2]);
  Console::setLayerTracking(false);
  expect(Console::getBuffer())->toHaveCount(5)->and(Console::snapshot()->rows[2])->toBe(' X          ');
  Console::setLayerTracking(true);
  Console::recomposeFrame(fn() => Console::write('Y', 1, 3));
  Console::setTerminalOutputEnabled(true);
  expect(Console::getBuffer())->toHaveCount(5)->and(Console::snapshot()->rows[3])->toBe(' Y          ');
  Console::setTerminalOutputEnabled(false);
});
