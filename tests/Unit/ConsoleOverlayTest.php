<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['overlays' => [], 'terminalHandedBack' => false, 'terminalOutputEnabled' => true,
    'usingAlternateScreen' => false, 'terminalOutputStream' => null, 'frameDepth' => 0,
    'isRecomposing' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  Console::syncDimensions(40, 8);
  Console::setLayerTracking(false);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->consoleState as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
});

it('removes an opaque overlay to reveal live styled content without repainting the scene', function (bool $output) {
  Console::setTerminalOutputEnabled($output);
  Console::write("\e[31mOld map\e[0m", 2, 2);
  Console::replaceOverlay('notice', ['       '], 2, 2, 2000);
  Console::write("\e[32mNew map\e[0m", 2, 2);
  expect(Console::snapshot()->rows[2])->not->toContain('New map')
    ->and(Console::snapshot(['notice'])->rows[2])->toContain('New map');
  $layers = Console::presentationSnapshot();
  expect(array_column($layers->textLayers, 'id'))->toContain('notice');
  ob_clean();
  Console::removeOverlay('notice');
  $coloured = array_values(array_filter(Console::presentationSnapshot()->textLayers[0]->runs,
    static fn($run): bool => $run->text === 'New map'));
  expect(Console::snapshot()->rows[2])->toContain('New map')
    ->and($coloured)->toHaveCount(1)
    ->and($coloured[0]->foreground)->not->toBeNull()
    ->and(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('notice');
  expect(ob_get_contents() !== '')->toBe($output);
})->with([true, false]);

it('moves an overlay atomically and removes lower overlays without damaging higher ones', function () {
  Console::write(str_repeat('.', 40), 0, 1);
  Console::replaceOverlay('low', ['LOW'], 5, 1, 1000);
  Console::replaceOverlay('high', ['HI'], 6, 1, 2000);
  expect(substr(Console::snapshot()->rows[1], 5, 3))->toBe('LHI');
  Console::removeOverlay('low');
  expect(substr(Console::snapshot()->rows[1], 5, 3))->toBe('.HI');
  Console::replaceOverlay('high', ['HI'], 10, 1, 2000);
  expect(substr(Console::snapshot()->rows[1], 5, 7))->toBe('.....HI');
  Console::removeOverlay('high');
  expect(Console::snapshot()->rows[1])->toBe(str_repeat('.', 40));
});

it('preserves overlays across scene replacement and restores the new screen beneath them', function () {
  Console::write('field', 0, 0);
  Console::replaceOverlay('notice', ['toast'], 0, 0, 2000);
  Console::clear();
  expect(Console::snapshot()->rows[0])->toStartWith('toast');
  Console::recomposeFrame(fn() => Console::write('map!!', 0, 0));
  expect(Console::snapshot()->rows[0])->toStartWith('toast');
  Console::removeOverlay('notice');
  expect(Console::snapshot()->rows[0])->toStartWith('map!!');
});

it('respects higher scene layers both before and after the overlay is introduced', function (bool $transitionFirst) {
  $transition = fn() => Console::withLayer('fade', fn() => Console::write('BLACK', 0, 0), 3000);
  if ($transitionFirst) { $transition(); }
  Console::replaceOverlay('notice', ['toast'], 0, 0, 2000);
  if (!$transitionFirst) { $transition(); }
  expect(Console::snapshot()->rows[0])->toStartWith('BLACK');
  Console::write('field', 0, 0);
  expect(Console::snapshot()->rows[0])->toStartWith('toast');
  Console::removeOverlay('notice');
  expect(Console::snapshot()->rows[0])->toStartWith('field');
})->with([true, false]);

it('restores wide glyphs and clips an overlay without splitting its glyphs', function () {
  Console::write("\e[34m界\e[0m", 2, 2);
  $before = Console::getBuffer();
  Console::replaceOverlay('notice', ['x'], 3, 2, 2000);
  expect(TerminalText::stripAnsi(Console::getBuffer()[2]))->not->toContain('界');
  Console::removeOverlay('notice');
  expect(Console::getBuffer())->toBe($before);
  Console::replaceOverlay('notice', ['界x'], -1, 0, 2000);
  expect(Console::snapshot()->rows[0])->toStartWith(' x');
  Console::syncDimensions(3, 2);
  Console::replaceOverlay('notice', ['界'], 2, 0, 2000);
  expect(Console::snapshot()->rows[0])->toBe('   ');
  Console::presentationSnapshot();
});

it('rolls overlay changes back when screen composition fails', function () {
  Console::write('field', 0, 0);
  Console::replaceOverlay('notice', ['old'], 1, 1, 2000);
  $before = Console::presentationSnapshot();
  expect(fn() => Console::recomposeFrame(function (): void {
    Console::replaceOverlay('notice', ['new'], 2, 2, 2000);
    throw new RuntimeException('failed frame');
  }))->toThrow(RuntimeException::class, 'failed frame');
  expect(Console::presentationSnapshot())->toEqual($before)->and(Console::isComposing())->toBeFalse();
});

it('does not emit hidden writes or split a wide overlay when its underlay changes', function () {
  Console::replaceOverlay('notice', ['界'], 1, 1, 2000);
  ob_clean();
  Console::write('x', 2, 1);
  expect(ob_get_contents())->toBe('')
    ->and(TerminalText::stripAnsi(Console::getBuffer()[1]))->toContain('界');
  Console::removeOverlay('notice');
  expect(Console::snapshot()->rows[1][2])->toBe('x');
});

it('recomposes same-glyph scene writes when their layer precedence changes', function () {
  Console::withLayer('cover', fn() => Console::write('x', 0, 0), 3000);
  Console::replaceOverlay('notice', ['n'], 0, 0, 2000);
  ob_clean();
  Console::write('x', 0, 0);
  expect(Console::snapshot()->rows[0][0])->toBe('n')->and(ob_get_contents())->toContain('n');
});

function flattenedOverlaySnapshot(array $excluded = []): array
{
  $snapshot = Console::presentationSnapshot($excluded);
  $rows = array_fill(0, $snapshot->height, array_fill(0, $snapshot->width, ' '));
  foreach ($snapshot->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      foreach (mb_str_split($run->text) as $offset => $scalar) { $rows[$run->row][$run->column + $offset] = $scalar; }
    }
  }
  return array_map(static fn(array $row): string => implode('', $row), $rows);
}

it('preserves structured and terminal parity when overlays cover part of a wide lower glyph', function (string $underlay) {
  $draw = fn() => Console::write("a\e[34m界\e[0mb", 0, 0);
  match ($underlay) {
    'world' => $draw(),
    'named' => Console::withLayer('map', $draw, 1000),
    'overlay' => Console::replaceOverlay('lower', ["a\e[34m界\e[0mb"], 0, 0, 1000),
  };
  $before = Console::presentationSnapshot();
  Console::replaceOverlay('notice', ['x'], 2, 0, 2000);
  expect(Console::snapshot()->rows[0])->toStartWith('a xb')
    ->and(flattenedOverlaySnapshot())->toBe(Console::snapshot()->rows)
    ->and(flattenedOverlaySnapshot(['notice']))->toBe(Console::snapshot(['notice'])->rows);
  Console::removeOverlay('notice');
  expect(Console::presentationSnapshot())->toEqual($before);
})->with(['world', 'named', 'overlay']);

it('clips retained wide overlays consistently when resized and restores them when expanded', function () {
  Console::syncDimensions(5, 2);
  Console::replaceOverlay('notice', ['界'], 3, 0, 2000);
  Console::syncDimensions(4, 2);
  expect(flattenedOverlaySnapshot())->toBe(Console::snapshot()->rows)
    ->and(Console::snapshot()->rows[0])->toBe('    ');
  Console::syncDimensions(5, 2);
  expect(flattenedOverlaySnapshot())->toBe(Console::snapshot()->rows)
    ->and(Console::snapshot()->rows[0])->toBe('   界 ');
});

it('keeps a higher scene layer above a wide overlay in both outputs', function () {
  Console::replaceOverlay('notice', ['界'], 1, 0, 2000);
  Console::withLayer('fade', fn() => Console::write('x', 2, 0), 3000);
  expect(flattenedOverlaySnapshot())->toBe(Console::snapshot()->rows)
    ->and(flattenedOverlaySnapshot(['fade']))->toBe(Console::snapshot(['fade'])->rows);
});

it('places overlays at or above world priority and rejects lower priorities atomically', function () {
  Console::write('base', 0, 0);
  $before = Console::presentationSnapshot();
  expect(fn() => Console::replaceOverlay('under', ['----'], 0, 0, -1))->toThrow(InvalidArgumentException::class);
  expect(Console::presentationSnapshot())->toEqual($before);
  Console::replaceOverlay('equal', ['----'], 0, 0, 0);
  expect(flattenedOverlaySnapshot())->toBe(Console::snapshot()->rows)
    ->and(Console::snapshot()->rows[0])->toStartWith('----');
});

it('shares the presentation layer budget across world, scene layers and overlays', function (int $named) {
  Console::setTerminalOutputEnabled(false);
  for ($i = 0; $i < $named; $i++) {
    Console::withLayer('ui-' . $i, fn() => Console::write('u', $i, 0), 1000);
  }
  for ($i = 0; $i < 63 - $named; $i++) { Console::replaceOverlay('notice-' . $i, ['n'], 0, 0, 2000); }
  $before = Console::presentationSnapshot();
  expect($before->textLayers)->toHaveCount(64);
  expect(fn() => Console::replaceOverlay('excess', ['x'], 0, 0, 2000))->toThrow(OverflowException::class);
  expect(fn() => Console::withLayer('excess', fn() => Console::write('x', 0, 0), 1000))->toThrow(OverflowException::class);
  expect(Console::presentationSnapshot())->toEqual($before);
  Console::replaceOverlay('notice-0', ['updated'], 0, 0, 2000);
  Console::removeOverlay('notice-0');
  Console::withLayer('replacement', fn() => Console::write('u', 0, 0), 1000);
  expect(Console::presentationSnapshot()->textLayers)->toHaveCount(64);
})->with([0, 2]);

it('rejects an overflowing parent layer write after a nested layer consumes the last slot', function () {
  Console::setTerminalOutputEnabled(false);
  for ($i = 0; $i < 62; $i++) { Console::replaceOverlay('notice-' . $i, ['n'], 3, 0, 2000); }
  expect(fn() => Console::withLayer('outer', function (): void {
    Console::withLayer('inner', fn() => Console::write('I', 0, 0), 1000);
    Console::write('O', 1, 0);
  }, 1000))->toThrow(OverflowException::class);
  $snapshot = Console::presentationSnapshot();
  expect($snapshot->textLayers)->toHaveCount(64)
    ->and(array_column($snapshot->textLayers, 'id'))->not->toContain('outer')
    ->and(Console::snapshot()->rows[0])->toStartWith('I ');
});
