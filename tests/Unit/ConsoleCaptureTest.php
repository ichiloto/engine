<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;

beforeEach(function () {
  $this->priorConsole = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalOutputEnabled' => false,
    'terminalHandedBack' => false, 'usingAlternateScreen' => false, 'terminalOutputStream' => null,
    'output' => null, 'overlays' => [], 'buffer' => [], 'layerCells' => [], 'layerPriorities' => [],
    'presentationBaseCells' => [], 'retainedPresentation' => null, 'trackLayers' => false,
    'activeLayer' => null, 'activeLayerPriority' => 0, 'retainedWorldPresentation' => false,
    'frameRows' => [], 'recomposeRepaintRows' => [], 'captureDepth' => 0] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(18, 6);
  Console::setLayerTracking(true);
  Console::withLayer('live-ui', fn() => Console::write('LIVE', 1, 1), PresentationLayerPolicy::UI);
  Console::replaceOverlay('live-notice', ['Notice'], 5, 3, PresentationLayerPolicy::NOTIFICATIONS);
  Console::getRetainedPresentationChanges();
  $this->beforeCapture = new ReflectionClass(Console::class)->getStaticProperties();
  ob_start();
});

afterEach(function () {
  expect(ob_get_clean())->toBe('');
  foreach ($this->priorConsole as $name => $value) { new ReflectionProperty(Console::class, $name)->setValue(null, $value); }
});

function getCaptureLayer(ConsolePresentationSnapshot $snapshot, string $id): mixed
{
  return array_find($snapshot->textLayers, static fn($layer): bool => $layer->id === $id);
}

it('captures layered styled text and notices without borrowing the live surface or cursor', function () {
  $snapshot = Console::capturePresentation(12, 4, static function () {
    expect(Console::getWidth())->toBe(12)->and(Console::getHeight())->toBe(4)
      ->and(Console::isTerminalOutputEnabled())->toBeFalse();
    Console::withLayer('actor', fn() => Console::write("\e[31mA", 2, 1), 100);
    Console::replaceOverlay('preview-notice', ['New'], 3, 2, PresentationLayerPolicy::NOTIFICATIONS);
  });
  expect($snapshot->width)->toBe(12)->and($snapshot->height)->toBe(4)
    ->and(array_column($snapshot->textLayers, 'id'))->toBe(['world', 'actor', 'preview-notice'])
    ->and(getCaptureLayer($snapshot, 'actor')->runs[0]->foreground?->toArray())->toBe(['kind' => 'ansi16', 'index' => 1])
    ->and(getCaptureLayer($snapshot, 'preview-notice')->runs[0]->text)->toBe('New')
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture)
    ->and(Console::getRetainedPresentationChanges()->layers)->toBe([]);
});

it('uses the production world exclusion policy while retaining later opaque UI', function () {
  $snapshot = Console::capturePresentation(8, 3, static function () {
    Console::write('base', 0, 0);
    Console::withLayer('older', fn() => Console::write('O', 1, 0));
    Console::withLayer('map', fn() => Console::write('....', 0, 0));
    Console::withLayer('actor', fn() => Console::write('A', 1, 0), 100);
    Console::withLayer('ui', fn() => Console::write(' ', 2, 0), PresentationLayerPolicy::UI);
  }, ['actor'], ['map'], true);
  expect(array_column($snapshot->textLayers, 'id'))->toBe(['world', 'ui'])
    ->and(getCaptureLayer($snapshot, 'world')->runs)->toBe([])
    ->and(getCaptureLayer($snapshot, 'ui')->runs[0]->column)->toBe(2)
    ->and(getCaptureLayer($snapshot, 'ui')->runs[0]->text)->toBe(' ')
    ->and($snapshot->rows)->toBe(array_fill(0, 3, str_repeat(' ', 8)))
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
});

it('keeps authored spaces and repairs wide glyphs through the existing row composer', function () {
  $snapshot = Console::capturePresentation(8, 3, static function () {
    Console::write(' ', 6, 2);
    Console::withLayer('wide', fn() => Console::write('界Z', 1, 1), 100);
    Console::withLayer('ui', fn() => Console::write('B', 2, 1), PresentationLayerPolicy::UI);
  }, retainedWorld: true);
  expect(getCaptureLayer($snapshot, 'world')->runs[0]->text)->toBe(' ')
    ->and(getCaptureLayer($snapshot, 'world')->runs[0]->column)->toBe(6)
    ->and(getCaptureLayer($snapshot, 'wide')->runs[0]->text)->toBe('  Z')
    ->and(getCaptureLayer($snapshot, 'ui')->runs[0]->column)->toBe(1)
    ->and(getCaptureLayer($snapshot, 'ui')->runs[0]->text)->toBe(' B')
    ->and($snapshot->rows)->toBe(['        ', '  BZ    ', '        ']);
});

it('restores the previous capture after nesting and the live console afterwards', function () {
  $inner = null;
  $outer = Console::capturePresentation(7, 3, static function () use (&$inner) {
    Console::write('Outer', 1, 1);
    $inner = Console::capturePresentation(5, 2, fn() => Console::write('Inner', 0, 0));
    expect(Console::getWidth())->toBe(7)->and(Console::charAt(1, 1))->toBe('O');
    Console::write('!', 6, 1);
  });
  expect(getCaptureLayer($inner, 'world')->runs[0]->text)->toBe('Inner')
    ->and(getCaptureLayer($outer, 'world')->runs[1]->text)->toBe(' Outer!')
    ->and($inner->rows)->toBe(['Inner', '     '])
    ->and($outer->rows)->toBe(['       ', ' Outer!', '       '])
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
});

it('restores state when drawing fails or leaves an unbalanced frame', function (bool $unbalanced) {
  expect(fn() => Console::capturePresentation(8, 3, static function () use ($unbalanced) {
    Console::write('Partial', 0, 0);
    if ($unbalanced) { Console::beginFrame(); return; }
    throw new RuntimeException('Drawing failed');
  }))->toThrow(RuntimeException::class);
  expect(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
})->with([false, true]);

it('cannot turn on physical output even inside nested captures', function () {
  expect(fn() => Console::capturePresentation(8, 3, fn() => Console::capturePresentation(4, 2,
    fn() => Console::setTerminalOutputEnabled(true))))->toThrow(LogicException::class,
    'An isolated Console capture cannot acquire terminal output.');
  expect(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
});

it('preserves an open live frame and its output descriptor', function () {
  $stream = fopen('php://temp', 'w+');
  new ReflectionProperty(Console::class, 'terminalOutputStream')->setValue(null, $stream);
  Console::beginFrame();
  $prior = new ReflectionClass(Console::class)->getStaticProperties();
  Console::capturePresentation(6, 2, static function () {
    Console::reset();
    Console::saveTerminalSettings();
    Console::restoreTerminalSettings();
  });
  expect(is_resource($stream))->toBeTrue()
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($prior);
  Console::endFrame();
  fclose($stream);
});

it('validates the surface before drawing', function () {
  $called = false;
  expect(fn() => Console::capturePresentation(0, 2, static function () use (&$called) { $called = true; }))
    ->toThrow(InvalidArgumentException::class);
  expect($called)->toBeFalse()->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
});

it('captures the actual styled Terminal rows without scalar fallback or continuation placeholders', function () {
  $expected = null;
  $snapshot = Console::capturePresentation(14, 3, static function () use (&$expected) {
    Console::write("\e[1;38;2;20;40;60;48;5;123m\u{754C}e\u{301}\u{1F408}|", 1, 1);
    $expected = Console::getBuffer();
  });
  expect($snapshot->rows)->toBe($expected)->toHaveCount(3)
    ->and($snapshot->rows[0])->toBe(str_repeat(' ', 14))
    ->and($snapshot->rows[1])->toContain("\e[", "e\u{301}")->not->toContain("\0")
    ->and(TerminalText::stripAnsi($snapshot->rows[1]))->toContain("\u{754C}e\u{301}\u{1F408}|")
    ->and(implode('', array_column(getCaptureLayer($snapshot, 'world')->runs, 'text')))->toContain('?');
  foreach ($snapshot->rows as $row) { expect(TerminalText::displayWidth($row))->toBe(14); }
  Console::clear();
  Console::syncDimensions(4, 2);
  expect($snapshot->rows)->toBe($expected)->and($snapshot->width)->toBe(14)->and($snapshot->height)->toBe(3);
  expect(function () use ($snapshot) { $snapshot->rows[0] = 'changed'; })->toThrow(Error::class);
});

it('omits excluded named owners and overlays while restoring complete styled wide underlays', function () {
  $snapshot = Console::capturePresentation(8, 2, static function () {
    Console::write("\e[32m\u{754C}defg", 0, 0);
    Console::withLayer('actor', fn() => Console::write('@', 1, 0), 100);
    Console::withLayer('ui', fn() => Console::write("\e[1;31mU", 4, 0), PresentationLayerPolicy::UI);
    Console::replaceOverlay('notice', ["\e[44mN"], 0, 0, PresentationLayerPolicy::NOTIFICATIONS);
  }, ['actor', 'notice']);
  expect(array_column($snapshot->textLayers, 'id'))->toBe(['world', 'ui'])
    ->and(TerminalText::stripAnsi($snapshot->rows[0]))->toBe("\u{754C}deUg  ")
    ->and($snapshot->rows[0])->toContain("\e[32m", "\e[1m\e[31mU")->not->toContain("\0", 'N', '@')
    ->and(TerminalText::displayWidth($snapshot->rows[0]))->toBe(8)
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
});

it('limits retained-world exclusions to their footprints including older overlay blockers', function (bool $retainedWorld) {
  $snapshot = Console::capturePresentation(8, 2, static function () {
    Console::write('abcdefgh', 0, 0);
    Console::withLayer('older', fn() => Console::write('OOOOOOOO', 0, 0), PresentationLayerPolicy::UI + 100);
    Console::withLayer('map', fn() => Console::write('...', 2, 0));
    Console::withLayer('actor', fn() => Console::write('A', 2, 0), 100);
    Console::withLayer('ui', fn() => Console::write(' ', 3, 0), PresentationLayerPolicy::UI + 200);
    Console::replaceOverlay('notice', ["\e[32mVVVVVVVV"], 0, 0, PresentationLayerPolicy::UI);
  }, ['actor'], ['map'], $retainedWorld);
  expect(TerminalText::stripAnsi($snapshot->rows[0]))->toBe('OOV VOOO')
    ->and($snapshot->rows[0])->toContain("\e[32m")
    ->and($snapshot->rows[1])->toBe('        ')
    ->and(array_column($snapshot->textLayers, 'id'))->not->toContain('map', 'actor')
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture);
})->with([false, true]);

it('keeps nested selected rows detached from the outer capture and live retained cursor', function () {
  $inner = null;
  $outer = Console::capturePresentation(7, 2, static function () use (&$inner) {
    Console::withLayer('parent', static function () use (&$inner) {
      Console::write('Outer', 1, 0);
      $before = new ReflectionClass(Console::class)->getStaticProperties();
      $inner = Console::capturePresentation(5, 2, static function () {
        Console::write('Inner', 0, 0);
        Console::withLayer('hidden', fn() => Console::write('XXX', 1, 0));
        Console::replaceOverlay('notice', ['N'], 0, 0, PresentationLayerPolicy::NOTIFICATIONS);
      }, ['hidden', 'notice']);
      expect(new ReflectionClass(Console::class)->getStaticProperties())->toBe($before);
      Console::write('!', 6, 0);
    }, PresentationLayerPolicy::UI);
  });
  expect($inner->rows)->toBe(['Inner', '     '])->and($outer->rows)->toBe([' Outer!', '       '])
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($this->beforeCapture)
    ->and(Console::getRetainedPresentationChanges()->layers)->toBe([]);
});

it('detaches styled row references without fabricating rows for a layers-only snapshot', function () {
  $row = "\e[31mX\e[0m  ";
  $expected = $row;
  $snapshot = new ConsolePresentationSnapshot(3, 1, [], [&$row]);
  $row = 'new';
  expect($snapshot->rows)->toBe([$expected])
    ->and(new ConsolePresentationSnapshot(3, 1, [])->rows)->toBeNull();
  expect(function () use ($snapshot) { $snapshot->rows = []; })->toThrow(Error::class);
  foreach ([[], ['one', 'two'], [1 => 'one'], [12], ["\xFF"]] as $rows) {
    expect(fn() => new ConsolePresentationSnapshot(3, 1, [], $rows))->toThrow(InvalidArgumentException::class);
  }
});
