<?php

use Ichiloto\Engine\IO\Console\Console;

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
