<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth'=>0,'isRecomposing'=>false,'terminalHandedBack'=>false,'usingAlternateScreen'=>false,
    'output'=>null,'terminalOutputStream'=>null,'terminalOutputEnabled'=>true,'terminalViewport'=>null,
    'terminalOffsetX'=>0,'terminalOffsetY'=>0] as $name=>$value) {
    new ReflectionProperty(Console::class,$name)->setValue(null,$value);
  }
  Console::syncDimensions(135,36);
  Console::setLayerTracking(true);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->consoleState as $name=>$value) { new ReflectionProperty(Console::class,$name)->setValue(null,$value); }
});

it('centers the entire logical viewport on both physical axes with odd margins floored', function ($physical,$logical,$origin) {
  Console::syncDimensions(...$logical);
  expect(Console::syncTerminalViewport(...$physical,repaint:false))->toBeTrue()
    ->and(Console::getTerminalOrigin())->toBe($origin)
    ->and([Console::getWidth(),Console::getHeight()])->toBe($logical)
    ->and(ob_get_contents())->toBe('');
})->with([
  [[186,38],[135,36],['x'=>25,'y'=>1]],
  [[220,60],[135,36],['x'=>42,'y'=>12]],
  [[186,38],[100,20],['x'=>43,'y'=>9]],
  [[135,36],[135,36],['x'=>0,'y'=>0]],
  [[80,24],[80,24],['x'=>0,'y'=>0]],
  [[80,24],[135,36],['x'=>0,'y'=>0]],
]);

it('shares the translated address across direct batched full and explicit region writes', function () {
  Console::syncTerminalViewport(186,38,repaint:false);
  Console::write('A',0,0);
  expect(ob_get_contents())->toContain("\e[2;26HA");
  ob_clean();
  Console::beginFrame();
  Console::write('B',134,35);
  Console::endFrame();
  expect(ob_get_contents())->toContain("\e[37;160HB");
  ob_clean();
  Console::repaintRegion(134,35,1,1);
  expect(ob_get_contents())->toBe("\e[37;160HB");
  ob_clean();
  Console::recomposeFrame(function () { Console::write('C',0,0); Console::write('D',134,35); }, true);
  expect(ob_get_contents())->toContain("\e[2;26HC")->toContain("\e[37;26H")
    ->and(Console::snapshot()->rows[0][0])->toBe('C')->and(Console::snapshot()->rows[35][134])->toBe('D');
});

it('keeps legacy one-based cursor semantics and clears to the translated home', function () {
  Console::syncTerminalViewport(186,38,repaint:false);
  Console::cursor()->moveTo(0,0);
  Console::cursor()->moveTo(4.8,3.9);
  Console::cursor()->moveRight(2);
  Console::cursor()->clearLine(1,1);
  expect(ob_get_contents())->toBe("\e[2;26H\e[4;29H\e[2C\e[2;26H\e[2K");
  ob_clean();
  Console::clear();
  expect(ob_get_contents())->toBe("\e[0m\e[2J\e[2;26H");
});

it('replays unchanged canonical styled layers after margin-only resize and removes stale physical content', function () {
  Console::syncTerminalViewport(186,38,repaint:false);
  Console::withLayer('hud',fn()=>Console::write("\e[32mX",0,0),1010);
  $before = Console::presentationSnapshot();
  $buffer = Console::getBuffer();
  ob_clean();
  expect(Console::syncTerminalViewport(220,60))->toBeTrue()
    ->and(Console::getTerminalOrigin())->toBe(['x'=>42,'y'=>12])
    ->and(Console::presentationSnapshot())->toEqual($before)->and(Console::getBuffer())->toBe($buffer)
    ->and(ob_get_contents())->toStartWith("\e[0m\e[2J\e[13;43H\e[13;43H")
    ->and(ob_get_contents())->toContain("\e[48;43H");
  ob_clean();
  expect(Console::syncTerminalViewport(220,60))->toBeFalse()->and(ob_get_contents())->toBe('');
  Console::beginFrame();
  Console::write('Y',134,35);
  Console::endFrame();
  expect(ob_get_contents())->toBe("\e[48;177HY");
});

it('repairs physical reflow even when rounded margins stay equal and never shifts graphical snapshots', function () {
  Console::syncTerminalViewport(186,38,repaint:false);
  expect(Console::syncTerminalViewport(185,39))->toBeTrue()->and(Console::getTerminalOrigin())->toBe(['x'=>25,'y'=>1]);
  Console::setTerminalOutputEnabled(false);
  ob_clean();
  expect(Console::syncTerminalViewport(220,60))->toBeFalse()->and(Console::getTerminalOrigin())->toBe(['x'=>0,'y'=>0]);
  Console::write('G',0,0);
  Console::cursor()->moveTo(0,0);
  expect(Console::snapshot()->rows[0][0])->toBe('G')->and(ob_get_contents())->toBe('');
});

it('refuses origin changes during composition and restores neutral origin on cleanup', function () {
  Console::syncTerminalViewport(186,38,repaint:false);
  Console::beginFrame();
  expect(fn()=>Console::syncTerminalViewport(220,60))->toThrow(LogicException::class);
  Console::endFrame();
  expect(Console::getTerminalOrigin())->toBe(['x'=>25,'y'=>1]);
  Console::enterAlternateScreen();
  ob_clean();
  Console::reset();
  expect(ob_get_contents())->toStartWith("\e[?7h\e[?1049l")
    ->and(Console::getTerminalOrigin())->toBe(['x'=>0,'y'=>0]);
});

it('keeps a failed physical replay retryable without changing the logical snapshot', function () {
  Console::syncTerminalViewport(186, 38, repaint: false);
  Console::write('X', 0, 0);
  $before = Console::presentationSnapshot();
  $output = new ReflectionProperty(Console::class, 'output');
  $output->setValue(null, new class extends \Symfony\Component\Console\Output\ConsoleOutput {
    public function __construct() {}
    public function getStream() { throw new RuntimeException('Unavailable terminal sink'); }
  });
  expect(fn() => Console::syncTerminalViewport(220, 60))->toThrow(RuntimeException::class, 'Unavailable terminal sink');
  $output->setValue(null, null);
  expect(Console::getTerminalOrigin())->toBe(['x' => 25, 'y' => 1])
    ->and(Console::presentationSnapshot())->toEqual($before);
  ob_clean();
  expect(Console::syncTerminalViewport(220, 60))->toBeTrue()
    ->and(ob_get_contents())->toStartWith("\e[0m\e[2J\e[13;43H\e[13;43HX");
});

function drawTerminalViewportFrame(string $mode, callable $draw): void
{
  if ($mode === 'recompose' || $mode === 'full') {
    Console::recomposeFrame($draw, $mode === 'full');
    return;
  }
  if ($mode !== 'immediate') { Console::beginFrame(); }
  $draw();
  if ($mode !== 'immediate') { Console::endFrame(); }
  if ($mode === 'region') {
    ob_clean();
    Console::repaintRegion(0, 0, Console::getWidth(), Console::getHeight());
  }
}

it('clips a physical shrink without changing the retained modal canvas and restores it on regrowth', function () {
  Console::syncTerminalViewport(186, 38, repaint: false);
  Console::withLayer('modal', function (): void {
    Console::write('VISIBLE', 0, 23);
    Console::write('OFFSCREEN', 0, 35);
    Console::write('RIGHT', 130, 0);
  }, 1020);
  $buffer = Console::getBuffer();
  $snapshot = Console::presentationSnapshot();
  ob_clean();

  Console::syncTerminalViewport(80, 24);
  $expected = "\e[0m\e[2J\e[H";
  for ($row = 0; $row < 24; $row++) {
    $expected .= sprintf("\e[%d;1H%s", $row + 1, substr($buffer[$row], 0, 80));
  }
  expect(ob_get_contents())->toBe($expected)
    ->and(Console::getBuffer())->toBe($buffer)
    ->and(Console::presentationSnapshot())->toEqual($snapshot)
    ->and([Console::getWidth(), Console::getHeight()])->toBe([135, 36]);

  ob_clean();
  Console::syncTerminalViewport(186, 38);
  $expected = "\e[0m\e[2J\e[2;26H";
  foreach ($buffer as $row => $line) { $expected .= sprintf("\e[%d;26H%s", $row + 2, $line); }
  expect(ob_get_contents())->toBe($expected)
    ->and(Console::presentationSnapshot())->toEqual($snapshot);
});

it('clips every native drawing path while keeping off-screen writes in the logical canvas', function (string $mode) {
  Console::syncDimensions(8, 3);
  Console::syncTerminalViewport(5, 2, repaint: false);
  drawTerminalViewportFrame($mode, function (): void {
    Console::write('ABCDEF', 2, 1);
    Console::write('R', 7, 0);
    Console::write('B', 0, 2);
  });
  $expected = in_array($mode, ['full', 'region'], true)
    ? "\e[1;1H     \e[2;1H  ABC" : "\e[2;3HABC";
  expect(ob_get_contents())->toBe($expected)
    ->and(Console::getBuffer())->toBe(['       R', '  ABCDEF', 'B       ']);
})->with(['immediate', 'batched', 'recompose', 'full', 'region']);

it('emits only whole wide glyphs within physical bounds on every native path', function (string $mode, string $glyph, int $x) {
  Console::syncDimensions(8, 3);
  Console::syncTerminalViewport(5, 2, repaint: false);
  drawTerminalViewportFrame($mode, fn() => Console::write($glyph, $x, 0));
  $symbol = TerminalText::stabilizeSymbol(TerminalText::firstSymbol($glyph));
  $visible = $x === 3 ? $symbol : ($x === 4 ? ' ' : '');
  $expected = $visible === '' ? '' : sprintf("\e[1;%dH%s", $x + 1, $visible);
  if (in_array($mode, ['full', 'region'], true)) {
    $line = $x < 5 ? str_repeat(' ', $x) . $visible : '     ';
    $expected = "\e[1;1H{$line}\e[2;1H     ";
  }
  expect(ob_get_contents())->toBe($expected)
    ->and(TerminalText::displayWidth(Console::getBuffer()[0]))->toBe(8);
  $snapshot = Console::presentationSnapshot();
  $buffer = Console::getBuffer();
  ob_clean();
  Console::syncTerminalViewport(8, 3);
  expect(ob_get_contents())->toContain($symbol)
    ->and(Console::getBuffer())->toBe($buffer)
    ->and(Console::presentationSnapshot())->toEqual($snapshot);
})->with(['immediate', 'batched', 'recompose', 'full', 'region'])
  ->with(["\e[31m\u{754C}\e[0m", "\u{1F5E1}\u{FE0F}", "\u{754C}\u{0301}"])
  ->with([3, 4, 5]);

it('repairs a complete wide glyph when a region targets either half of it', function (int $x) {
  Console::syncDimensions(8, 3);
  Console::syncTerminalViewport(10, 5, repaint: false);
  Console::write("\u{754C}", 2, 0);
  $buffer = Console::getBuffer();
  ob_clean();
  Console::repaintRegion($x, 0, 1, 1);
  expect(ob_get_contents())->toBe("\e[2;4H\u{754C}")
    ->and(Console::getBuffer())->toBe($buffer);
})->with([2, 3]);

it('recomputes the origin inside logical dimension changes before another terminal probe', function () {
  Console::syncTerminalViewport(186, 38, repaint: false);
  Console::syncDimensions(80, 24);
  expect(Console::getTerminalOrigin())->toBe(['x' => 53, 'y' => 7])
    ->and(ob_get_contents())->toBe('');
  Console::write('A', 0, 0);
  Console::write('B', 79, 23);
  expect(ob_get_contents())->toBe("\e[8;54HA\e[31;133HB");
  ob_clean();
  expect(Console::syncTerminalViewport(186, 38))->toBeTrue()
    ->and(ob_get_contents())->toStartWith("\e[0m\e[2J\e[8;54H");
  Console::syncDimensions(200, 60);
  expect(Console::getTerminalOrigin())->toBe(['x' => 0, 'y' => 0]);
  ob_clean();
  Console::write('hidden', 186, 0);
  Console::write('hidden', 0, 38);
  expect(ob_get_contents())->toBe('');
});

it('invalidates the old physical geometry when requesting a terminal resize', function () {
  Console::syncTerminalViewport(186, 38, repaint: false);
  Console::write('old', 134, 35);
  ob_clean();
  Console::setTerminalSize(80, 24);
  expect(Console::getTerminalOrigin())->toBe(['x' => 0, 'y' => 0])
    ->and(ob_get_contents())->toBe("\e[8;24;80t")
    ->and(Console::getBuffer())->toBe(array_fill(0, 24, str_repeat(' ', 80)));
  ob_clean();
  Console::write('A', 0, 0);
  Console::write('B', 79, 23);
  expect(ob_get_contents())->toBe("\e[1;1HA\e[24;80HB");
  ob_clean();
  expect(Console::syncTerminalViewport(80, 24))->toBeTrue()
    ->and(ob_get_contents())->toStartWith("\e[0m\e[2J\e[H");
});

it('invalidates physical presentation even when logical dimensions return to their previous values', function () {
  Console::syncTerminalViewport(186, 38, repaint: false);
  Console::syncDimensions(80, 24);
  Console::write('old footprint', 0, 0);
  Console::syncDimensions(135, 36);
  ob_clean();
  expect(Console::syncTerminalViewport(186, 38))->toBeTrue()
    ->and(ob_get_contents())->toStartWith("\e[0m\e[2J\e[2;26H");
  ob_clean();
  expect(Console::syncTerminalViewport(186, 38))->toBeFalse()
    ->and(ob_get_contents())->toBe('');
});

it('rejects dimension changes inside an active frame before changing state or emitting a resize', function (string $method) {
  Console::syncTerminalViewport(186, 38, repaint: false);
  $buffer = Console::getBuffer();
  Console::beginFrame();
  expect(fn() => Console::{$method}(80, 24))->toThrow(LogicException::class);
  Console::endFrame();
  expect(Console::getTerminalOrigin())->toBe(['x' => 25, 'y' => 1])
    ->and([Console::getWidth(), Console::getHeight()])->toBe([135, 36])
    ->and(Console::getBuffer())->toBe($buffer)
    ->and(ob_get_contents())->toBe('');
})->with(['syncDimensions', 'setTerminalSize']);
