<?php

use Ichiloto\Engine\IO\Console\Console;
use Symfony\Component\Console\Output\ConsoleOutput;

beforeEach(function () {
  $this->before = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['overlays' => [], 'terminalHandedBack' => false, 'terminalOutputEnabled' => true,
    'usingAlternateScreen' => false, 'terminalOutputStream' => null, 'output' => null,
    'frameDepth' => 0, 'frameRows' => [], 'isRecomposing' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  ob_start();
  Console::syncDimensions(40, 8);
  Console::clear();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->before as $key => $value) { new ReflectionProperty(Console::class, $key)->setValue(null, $value); }
});

it('removes live ownership in terminal and native modes retaining styled base other owners and overlays', function (bool $tracking, bool $output) {
  Console::setLayerTracking($tracking);
  Console::setTerminalOutputEnabled($output);
  Console::write("\e[32mbase\e[0m", 0, 0);
  $base = Console::presentationSnapshot();
  Console::withLayer('config', fn() => Console::write('CONFIG', 0, 0), 1000);
  Console::withLayer('other', fn() => Console::write('!', 3, 0), 3000);
  Console::replaceOverlay('notice', ['TOP'], 0, 0, 2000);
  ob_clean();
  Console::removeLayer('config');
  expect(Console::snapshot()->rows[0])->toStartWith('TOP!');
  expect(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('config')->toContain('other', 'notice');
  Console::removeLayer('other');
  Console::removeOverlay('notice');
  expect(Console::presentationSnapshot())->toEqual($base);
  ob_clean();
  Console::removeLayer('config');
  expect(ob_get_contents())->toBe('');
})->with([false, true])->with([false, true]);

it('restores complete wide underlays and keeps newer world writes when ownership is released', function () {
  Console::write("\e[34m界\e[0m!", 0, 0);
  $base = Console::getBuffer();
  Console::withLayer('config', fn() => Console::write('x', 1, 0), 1000);
  Console::removeLayer('config');
  expect(Console::getBuffer())->toBe($base);
  Console::withLayer('config', fn() => Console::write('x', 0, 0), 1000);
  Console::write('N', 1, 0);
  Console::removeLayer('config');
  expect(Console::snapshot()->rows[0])->toStartWith(' N!');
  expect(liveLayerRows())->toBe(Console::snapshot()->rows);
});

function liveLayerRows(): array
{
  $snapshot = Console::presentationSnapshot();
  $rows = array_fill(0, $snapshot->height, array_fill(0, $snapshot->width, ' '));
  foreach ($snapshot->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      foreach (mb_str_split($run->text) as $offset => $char) { $rows[$run->row][$run->column + $offset] = $char; }
    }
  }
  return array_map(fn($row) => implode('', $row), $rows);
}

it('retains a wide underlay until the last overlapping live owner is removed', function () {
  Console::write('界!', 0, 0);
  Console::withLayer('first', fn() => Console::write('x', 0, 0), 1000);
  Console::withLayer('second', fn() => Console::write('y', 1, 0), 2000);
  Console::removeLayer('first');
  expect(Console::snapshot()->rows[0])->toStartWith(' y!')->and(liveLayerRows())->toBe(Console::snapshot()->rows);
  Console::removeLayer('second');
  expect(Console::snapshot()->rows[0])->toStartWith('界 !')->and(liveLayerRows())->toBe(Console::snapshot()->rows);
});

it('disposes without output including after handback and inside another frame', function (bool $handedBack) {
  Console::write('base', 0, 0);
  Console::withLayer('config', fn() => Console::write('over', 0, 0), 1000);
  new ReflectionProperty(Console::class, 'terminalHandedBack')->setValue(null, $handedBack);
  ob_clean();
  Console::removeLayer('config', repaint: false);
  expect(ob_get_contents())->toBe('')->and(Console::snapshot()->rows[0])->toStartWith('base');
  new ReflectionProperty(Console::class, 'terminalHandedBack')->setValue(null, false);
  Console::beginFrame();
  Console::write('new', 5, 0);
  Console::withLayer('config', fn() => Console::write('over', 0, 0), 1000);
  Console::removeLayer('config');
  expect(Console::isComposing())->toBeTrue();
  Console::endFrame();
  expect(Console::snapshot()->rows[0])->toStartWith('base new');
})->with([false, true]);

it('restores state on emission failure and respects outer recomposition rollback', function () {
  Console::write('base', 0, 0);
  Console::withLayer('config', fn() => Console::write('over', 0, 0), 1000);
  $before = Console::presentationSnapshot();
  $output = new class extends ConsoleOutput {
    public function __construct() {}
    public function getStream() { throw new RuntimeException('failed sink'); }
  };
  new ReflectionProperty(Console::class, 'output')->setValue(null, $output);
  expect(fn() => Console::removeLayer('config'))->toThrow(RuntimeException::class, 'failed sink');
  expect(Console::presentationSnapshot())->toEqual($before)->and(Console::isComposing())->toBeFalse();
  new ReflectionProperty(Console::class, 'output')->setValue(null, null);
  expect(fn() => Console::recomposeFrame(function (): void {
    Console::withLayer('temporary', fn() => Console::write('temp', 2, 2));
    Console::removeLayer('temporary');
    throw new RuntimeException('failed compose');
  }))->toThrow(RuntimeException::class, 'failed compose');
  expect(Console::presentationSnapshot())->toEqual($before);
  Console::recomposeFrame(fn() => Console::write('new scene', 0, 0));
  Console::removeLayer('config');
  expect(Console::snapshot()->rows[0])->toStartWith('new scene');
});
