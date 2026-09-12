<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;

beforeEach(function () {
  $this->console = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['output' => null, 'terminalOutputStream' => null, 'usingAlternateScreen' => false,
    'terminalHandedBack' => false, 'frameDepth' => 0, 'frameRows' => [],
    'isRecomposing' => false, 'terminalOutputEnabled' => true] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  Console::syncDimensions(20, 4);
  Console::setLayerTracking(true);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->console as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
});

it('keeps identical canonical cells and styled layers without terminal paint or dirty spans', function () {
  $draw = function (): void {
    Console::clear();
    Console::beginFrame();
    Console::write('world', 0, 0);
    Console::withLayer('dialogue', fn() => Console::write("\e[31;44mHello\e[0m", 2, 1), 1000);
    Console::endFrame();
    Console::recomposeFrame(function (): void {
      Console::write('new world', 0, 0);
      Console::withLayer('dialogue', fn() => Console::write("\e[31;44mHello\e[0m", 2, 1), 1000);
      Console::repaintRegion(0, 0, 20, 4);
    }, true);
  };
  $draw();
  $cells = Console::getBuffer();
  $layers = Console::presentationSnapshot();
  expect(ob_get_contents())->not->toBe('');
  ob_clean();

  Console::setTerminalOutputEnabled(false);
  $draw();
  expect(Console::getBuffer())->toBe($cells)
    ->and(Console::presentationSnapshot())->toEqual($layers)
    ->and(Console::isComposing())->toBeFalse()
    ->and(new ReflectionProperty(Console::class, 'frameRows')->getValue())->toBe([])
    ->and(ob_get_contents())->toBe('');
});

it('keeps recomposition rollback and balancing in buffer-only mode', function () {
  Console::setTerminalOutputEnabled(false);
  Console::clear();
  Console::withLayer('dialogue', fn() => Console::write('original', 0, 0), 1000);
  $before = Console::presentationSnapshot();
  expect(fn() => Console::recomposeFrame(function (): void {
    Console::write('partial', 0, 0);
    throw new RuntimeException('failed draw');
  }))->toThrow(RuntimeException::class, 'failed draw');
  expect(Console::presentationSnapshot())->toEqual($before)
    ->and(Console::isComposing())->toBeFalse()->and(ob_get_contents())->toBe('');
});

it('initializes and closes a buffer-only session without borrowing terminal modes or a descriptor', function () {
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  Console::setTerminalOutputEnabled(false);
  Console::init($game, ['width' => 20, 'height' => 4]);
  Console::enterAlternateScreen();
  Console::disableLineWrap();
  Console::setTerminalName('Graphical');
  Console::setTerminalSize(20, 4);
  Console::cursor()->hide();
  expect(new ReflectionProperty(Console::class, 'terminalOutputStream')->getValue())->toBeNull()
    ->and(new ReflectionProperty(Console::class, 'usingAlternateScreen')->getValue())->toBeFalse()
    ->and(new ReflectionMethod(TerminalCapabilities::class, 'probeCompositeEmojiWidth')->invoke(null))->toBeNull();
  Console::write('visible in snapshot', 0, 0);
  expect(Console::charAt(0, 0))->toBe('v');
  Console::reset();
  Console::write('late', 0, 0);
  expect(Console::charAt(0, 0))->toBe('v')->and(ob_get_contents())->toBe('');
});

it('allows a later terminal session to paint normally and forbids changing a borrowed screen', function () {
  Console::setTerminalOutputEnabled(false);
  Console::clear();
  Console::write('buffer', 0, 0);
  Console::setTerminalOutputEnabled(true);
  Console::enterAlternateScreen();
  Console::clear();
  Console::write('terminal', 0, 0);
  expect(ob_get_contents())->toContain("\e[?1049h")->toContain('terminal')
    ->and(fn() => Console::setTerminalOutputEnabled(false))->toThrow(LogicException::class);
  Console::reset();
});

it('rejects sink changes inside an active frame without losing pending terminal paint', function () {
  Console::beginFrame();
  Console::write('pending', 0, 0);
  expect(fn() => Console::setTerminalOutputEnabled(false))->toThrow(LogicException::class);
  Console::endFrame();
  expect(Console::isTerminalOutputEnabled())->toBeTrue()
    ->and(ob_get_contents())->toContain('pending');
});
