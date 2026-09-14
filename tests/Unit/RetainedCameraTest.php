<?php

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->configState = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->capabilityState = new ReflectionClass(TerminalCapabilities::class)->getStaticProperties();
  ConfigStore::remove(ProjectConfig::class);
  new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, true);
  foreach (['overlays' => [], 'terminalHandedBack' => false, 'terminalOutputEnabled' => true,
    'usingAlternateScreen' => false, 'terminalOutputStream' => null, 'output' => null,
    'frameDepth' => 0, 'isRecomposing' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  Console::syncDimensions(12, 4);
  Console::setLayerTracking(false);
  $this->records = [];
  LatencyTrace::configure(function (array $record): void { $this->records[] = $record; });
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  LatencyTrace::configure();
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->configState);
  foreach ([Console::class => $this->consoleState, TerminalCapabilities::class => $this->capabilityState] as $class => $state) {
    foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
});

function retainedCamera(): Camera
{
  $rows = [];
  for ($y = 0; $y < 6; $y++) {
    $rows[] = TerminalText::visibleSymbols("<fg=blue>abcdefghijklmnop</>" . $y);
  }
  return new Camera(makeCameraTestScene(), 12, 4, worldSpace: $rows);
}

it('reuses map cells for idle, both pan axes, background restoration and menu return', function () {
  $camera = retainedCamera();
  $player = NormalizedRow::fromSymbols(['@']);
  $menu = NormalizedRow::fromSymbols(str_split('Menu'));
  // Prepare each map row once, including rows subsequently reached by a pan.
  foreach ([0, 2, 0] as $y) { $camera->moveTo(0, $y); $camera->renderMap(); }
  $field = Console::presentationSnapshot();
  $this->records = [];
  ob_clean();
  $camera->renderMap();
  expect(ob_get_contents())->toBe('');
  foreach ([[1, 0], [1, 1], [0, 2], [0, 0]] as [$x, $y]) {
    $camera->moveTo($x, $y);
    $camera->renderMap();
  }
  Console::writeNormalizedRow($player, 3, 1);
  $camera->renderBackgroundTile(3, 1);
  Console::writeNormalizedRow($player, 4, 1);
  $camera->renderBackgroundTile(4, 1);
  Console::recomposeFrame(fn() => Console::writeNormalizedRow($menu, 0, 0));
  Console::recomposeFrame($camera->renderMap(...));
  expect(Console::presentationSnapshot())->toEqual($field);
  $stages = array_column($this->records, 'stage');
  expect($stages)->toContain('terminal.select', 'terminal.compose', 'terminal.diff', 'terminal.serialize')
    ->not->toContain('terminal.normalize', 'terminal.tokenize', 'terminal.format');
});

it('keeps live retained scenery under moved and dismissed notifications in both output modes', function (bool $output) {
  Console::setTerminalOutputEnabled($output);
  $camera = retainedCamera();
  $camera->renderMap();
  Console::replaceOverlay('notice', ['      '], 1, 1, 2000);
  $this->records = [];
  ob_clean();
  $camera->moveTo(2, 0);
  $camera->renderMap();
  expect(Console::snapshot(['notice'])->rows[1])->toBe('cdefghijklmn');
  expect(array_column($this->records, 'stage'))->not->toContain('terminal.normalize', 'terminal.tokenize', 'terminal.format');
  Console::replaceOverlay('notice', ['      '], 5, 2, 2000);
  expect(Console::snapshot()->rows[1])->toBe('cdefghijklmn');
  Console::removeOverlay('notice');
  expect(Console::snapshot()->rows[2])->toBe('cdefghijklmn');
  if (!$output) { expect(ob_get_contents())->toBe(''); }
  Console::setTerminalOutputEnabled(true);
  ob_clean();
  Console::recomposeFrame($camera->renderMap(...), true);
  expect(ob_get_contents())->not->toBe('');
})->with([true, false]);

it('restores blank margins outside a centered map without copying an edge tile', function () {
  $camera = new Camera(makeCameraTestScene(), 12, 4, worldSpace: [['a', 'b']]);
  $camera->renderMap();
  foreach ([[-1, 0], [2, 0], [0, -1], [0, 1]] as [$x, $y]) {
    $position = $camera->getScreenSpacePosition(new \Ichiloto\Engine\Core\Vector2($x, $y));
    Console::write('@', $position->x, $position->y);
    $camera->renderBackgroundTile($x, $y);
    expect(Console::charAt($position->x, $position->y))->toBe(' ');
  }
  expect(Console::snapshot()->rows[1])->toBe('     ab     ');
});

it('refreshes changed rows, map reloads and width policy while geometry selects fresh bounds', function () {
  $camera = retainedCamera();
  $camera->renderMap();
  $changed = $camera->worldSpace;
  $changed[0][0] = 'Z';
  $camera->worldSpace = $changed;
  $camera->renderMap();
  expect(Console::charAt(0, 0))->toBe('Z');
  $camera->worldSpace = array_fill(0, 4, array_fill(0, 12, 'x'));
  $camera->renderMap();
  expect(Console::snapshot()->rows[0])->toBe(str_repeat('x', 12));
  Console::syncDimensions(8, 3);
  $camera->resizeViewport(8, 3);
  $this->records = [];
  $camera->moveTo(4, 1);
  $camera->renderMap();
  expect(Console::snapshot()->rows)->toBe(array_fill(0, 3, 'xxxxxxxx'))
    ->and(array_column($this->records, 'stage'))->not->toContain('terminal.normalize');
  $camera->worldSpace = array_fill(0, 3, ['🏃🏽‍➡️', 'a', 'b', 'c', 'd', 'e', 'f', 'g']);
  $camera->moveTo(0, 0);
  $camera->renderMap();
  $composite = Console::getBuffer();
  new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, false);
  $camera->renderMap();
  expect(Console::getBuffer())->not->toBe($composite);
  new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, true);
  $camera->renderMap();
  expect(Console::getBuffer())->toBe($composite);
});

it('retains graphical terrain and player provenance without normalizing the map again', function () {
  Console::setTerminalOutputEnabled(false);
  $camera = retainedCamera();
  $camera->renderMap();
  Console::setLayerTracking(true);
  $player = NormalizedRow::fromText("\e[38;2;0;120;0m@\e[0m");
  $blank = NormalizedRow::fromText("\e[48;5;0m \e[0m");
  $this->records = [];
  Console::withLayer('terrain', $camera->renderMap(...), 0, replaceUnderlying: true);
  Console::withLayer('player', fn() => Console::writeNormalizedRow($player, 2, 1), 100);
  Console::withLayer('ui', fn() => Console::writeNormalizedRow($blank, 2, 1), 1000);
  $before = Console::presentationSnapshot();
  $filtered = Console::presentationSnapshot(['terrain', 'player']);
  expect(array_column($filtered->textLayers, 'id'))->toContain('ui')->not->toContain('terrain', 'player');
  expect(Console::snapshot(['player', 'ui'])->rows[1])->toBe('abcdefghijkl')
    ->and(Console::presentationSnapshot())->toEqual($before)
    ->and(array_column($this->records, 'stage'))->not->toContain('terminal.normalize', 'terminal.tokenize', 'terminal.format');
});

it('formats string-authored maps once across scrolling, graphical collection and width-policy changes', function () {
  $camera = new Camera(makeCameraTestScene(), 12, 4,
    worldSpace: array_fill(0, 5, '<fg=red>🏃🏽‍➡️</>abcdefghijklmnop'));
  expect(array_count_values(array_column($this->records, 'stage'))['terminal.format'])->toBe(5);
  $this->records = [];
  $camera->renderMap();
  $camera->moveTo(1, 1);
  $camera->renderMap();
  iterator_to_array($camera->visibleMapRows());
  new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, false);
  $camera->renderMap();
  expect(array_column($this->records, 'stage'))->not->toContain('terminal.format', 'terminal.tokenize');
});

it('owns authored map values instead of retaining mutable PHP array aliases', function () {
  $symbol = 'a';
  $row = [&$symbol, 'b'];
  $camera = new Camera(makeCameraTestScene(), 2, 1, worldSpace: [&$row]);
  Console::syncDimensions(2, 1);
  $camera->renderMap();
  $symbol = 'z';
  $row[] = 'c';
  $camera->renderMap();
  expect($camera->worldSpace)->toBe([['a', 'b']])
    ->and(iterator_to_array($camera->visibleMapRows()))->toBe([['a', 'b']])
    ->and(Console::snapshot()->rows)->toBe(['ab']);
  $camera->worldSpace = [$row];
  $camera->renderMap();
  expect(Console::snapshot()->rows)->toBe(['zb']);
});

it('shares clipping and styled composition between string and normalized input', function (string $text) {
  foreach ([1, 2, 3, 7] as $width) {
    Console::syncDimensions($width + 2, 2);
    Console::write('界........', 0, 0);
    Console::write($text, 1, 0);
    $expected = Console::presentationSnapshot();
    Console::clear();
    Console::write('界........', 0, 0);
    Console::writeNormalizedRow(NormalizedRow::fromText($text, $width + 1), 1, 0);
    expect(Console::presentationSnapshot())->toEqual($expected);
  }
})->with([
  '<fg=blue>界</>AB', "e\u{0301}⚔️🗡", "🇺\e[0m🇸X", "👩\e[0m‍💻X",
  "\e[1;31mA\e[22mB\e[39mC", "\e[38;2;0;128;0;48;5;0m A\e[49mB",
  "\e[4;999;38;5;0mA\e[24mB\e[0mC",
]);

it('keeps authored map symbols separate across reset and combining boundaries', function () {
  $symbols = ['🇺', '🇸', 'e', "\u{0301}", 'x'];
  $row = NormalizedRow::fromSymbols($symbols);
  expect($row->cells[1])->toBe(NormalizedRow::CONTINUATION)
    ->and($row->cells[3])->toBe(NormalizedRow::CONTINUATION);
  Console::writeNormalizedRow($row, 0, 0);
  expect(Console::charAt(4, 0))->toBe('e')->and(Console::charAt(6, 0))->toBe('x');
  $camera = new Camera(makeCameraTestScene(), 12, 4, worldSpace: [$symbols]);
  expect(iterator_to_array($camera->visibleMapRows()))->toBe([$symbols]);
});

it('retries undelivered retained frame spans even when the next render is unchanged', function (bool $batched) {
  $camera = retainedCamera();
  $output = new ReflectionProperty(Console::class, 'output');
  $output->setValue(null, new class extends \Symfony\Component\Console\Output\ConsoleOutput {
    public function __construct() {}
    public function getStream() { throw new RuntimeException('Unavailable terminal sink'); }
  });
  $row = NormalizedRow::fromSymbols(str_split('abcdefghijkl'));
  $draw = $batched ? $camera->renderMap(...) : fn() => Console::writeNormalizedRow($row, 0, 0);
  expect($draw)->toThrow(RuntimeException::class, 'Unavailable terminal sink');
  $output->setValue(null, null);
  ob_clean();
  $draw();
  expect(TerminalText::stripAnsi(ob_get_contents()))->toContain('abcdefghijkl');
})->with([true, false]);
