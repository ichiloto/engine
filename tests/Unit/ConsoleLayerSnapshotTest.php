<?php

use Ichiloto\Engine\IO\Console\Console;

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(12, 4);
  Console::setLayerTracking(true);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->consoleState as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
});

it('excludes a named layer only from a new immutable snapshot and restores nonblank underlay', function () {
  Console::write('..floor..', 0, 1);
  Console::withLayer('player', fn() => Console::write('@', 3, 1));
  $original = Console::snapshot();
  $state = new ReflectionClass(Console::class)->getStaticProperties();
  $output = ob_get_contents();
  $masked = Console::snapshot(['player']);
  expect($original->rows[1])->toBe('..f@oor..   ')->and($masked->rows[1])->toBe('..floor..   ')
    ->and(Console::charAt(3, 1))->toBe('@')->and(Console::snapshot())->toEqual($original)
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($state)
    ->and(ob_get_contents())->toBe($output);
});

it('retains event cue and NPC underlay from the actual normal composition order', function () {
  Console::recomposeFrame(function () {
    Console::write('.....', 0, 1);
    Console::write('!', 2, 1); // Authored event cue, then a terminal NPC.
    Console::write('N', 3, 1);
    Console::withLayer('player', fn() => Console::write('@@', 2, 1));
  });
  expect(Console::snapshot(['player'])->rows[1])->toBe('..!N.       ');
});

it('preserves later overlays even when they write the identical player glyph', function ($overlay) {
  Console::write('abcd', 0, 0);
  Console::withLayer('player', fn() => Console::write('@@', 1, 0));
  Console::write($overlay, 1, 0);
  expect(Console::snapshot(['player'])->rows[0])->toBe('a' . $overlay . 'cd        ');
})->with(['@', 'X', ' ']);

it('masks every cell of wide and multi-row art without moving subsequent cells', function () {
  Console::write(['123456789ABC', 'abcdefghijkl'], 0, 0);
  Console::withLayer('player', fn() => Console::write(["\u{1F408}X", 'YZ'], 2, 0));
  $mirror = Console::snapshot();
  expect($mirror->rows[0])->toBe("12\u{1F408} X6789ABC")
    ->and($mirror->rows[1])->toBe('abYZefghijkl')
    ->and(Console::snapshot(['player'])->rows)->toBe(['123456789ABC', 'abcdefghijkl', '            ', '            ']);
});

it('restores wide underlay and protects whole glyphs touched by later overlays', function () {
  Console::write("a\u{1F408}def", 0, 0);
  Console::withLayer('player', fn() => Console::write('@', 2, 0));
  expect(Console::snapshot(['player'])->rows[0])->toBe("a\u{1F408} def      ");
  Console::write('Z', 1, 0);
  expect(Console::snapshot(['player'])->rows[0])->toBe('aZ def      ');
});

it('keeps redraw history bounded and handles overlapping named layers independently', function () {
  Console::write('.', 1, 1);
  for ($i = 0; $i < 10; $i++) {
    Console::withLayer('player', fn() => Console::write('@', 1, 1));
    Console::withLayer('other', fn() => Console::write('X', 1, 1));
  }
  expect(Console::snapshot(['player'])->rows[1][1])->toBe('X')
    ->and(Console::snapshot(['other'])->rows[1][1])->toBe('@')
    ->and(Console::snapshot(['player', 'other'])->rows[1][1])->toBe('.')
    ->and(new ReflectionProperty(Console::class, 'layerCells')->getValue()[1][1]['layers'])->toHaveCount(2);
});

it('rolls back provenance when normal composition fails and clears it on successful recomposition or resize', function () {
  Console::write('.', 1, 1);
  Console::withLayer('player', fn() => Console::write('@', 1, 1));
  $before = Console::snapshot(['player']);
  expect(fn() => Console::recomposeFrame(function () {
    Console::withLayer('player', fn() => Console::write('X', 1, 1));
    throw new RuntimeException('failed composition');
  }))->toThrow(RuntimeException::class);
  expect(Console::snapshot(['player']))->toEqual($before);
  Console::recomposeFrame(fn() => Console::write('menu', 0, 0));
  expect(Console::snapshot(['player']))->toEqual(Console::snapshot());
  Console::syncDimensions(5, 2);
  expect(Console::snapshot(['player'])->rows)->toBe(['     ', '     ']);
});

it('retains S4 incomplete-frame rejection and restores layer scope after exceptions', function () {
  expect(fn() => Console::withLayer('player', function () { throw new RuntimeException(); }))->toThrow(RuntimeException::class);
  Console::write('!', 0, 0);
  Console::beginFrame();
  expect(fn() => Console::snapshot(['player']))->toThrow(RuntimeException::class, 'active');
  Console::endFrame();
  expect(Console::snapshot(['player'])->rows[0][0])->toBe('!');
});
