<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(8, 3);
  Console::setLayerTracking(true);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->consoleState as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
});

it('extracts authored canonical colour without ANSI in renderer text', function ($sequence, $foreground, $background) {
  Console::write($sequence . 'X', 0, 0);
  $snapshot = Console::presentationSnapshot();
  $run = $snapshot->textLayers[0]->runs[0];
  expect($run->text)->toStartWith('X')->not->toContain("\e")
    ->and($run->foreground?->toArray())->toBe($foreground)
    ->and($run->background?->toArray())->toBe($background)
    ->and(Console::snapshot()->rows[0])->toBe('X       ');
})->with([
  'enum standard' => [Color::RED->value, ['kind' => 'ansi16', 'index' => 1], null],
  'bright code' => ["\e[94m", ['kind' => 'ansi16', 'index' => 12], null],
  'legacy bright' => [Color::LIGHT_RED->value, ['kind' => 'ansi16', 'index' => 9], null],
  'background' => ["\e[44m", null, ['kind' => 'ansi16', 'index' => 4]],
  'bright background' => ["\e[107m", null, ['kind' => 'ansi16', 'index' => 15]],
  'indexed bounds' => ["\e[38;5;0;48;5;255m", ['kind' => 'ansi256', 'index' => 0], ['kind' => 'ansi256', 'index' => 255]],
  'indexed bounds reversed' => ["\e[38;5;255;48;5;0m", ['kind' => 'ansi256', 'index' => 255], ['kind' => 'ansi256', 'index' => 0]],
  'rgb' => ["\e[38;2;0;135;255;48;2;255;0;17m", ['kind' => 'rgb', 'r' => 0, 'g' => 135, 'b' => 255], ['kind' => 'rgb', 'r' => 255, 'g' => 0, 'b' => 17]],
  'reset' => ["\e[31;44m\e[0m", null, null],
  'reset foreground' => ["\e[31;44m\e[39m", null, ['kind' => 'ansi16', 'index' => 4]],
  'reset background' => ["\e[31;44m\e[49m", ['kind' => 'ansi16', 'index' => 1], null],
  'reset intensity' => ["\e[1;31m\e[22;32m", ['kind' => 'ansi16', 'index' => 2], null],
  'blink enum' => [Color::WHITE_BLINK->value, ['kind' => 'ansi16', 'index' => 7], null],
  'ignored attributes' => ["\e[3;4;7;9;31m\e[23;24;27;29m", ['kind' => 'ansi16', 'index' => 1], null],
]);

it('extracts Symfony true colour and keeps independently reset background', function () {
  $mode = Symfony\Component\Console\Terminal::getColorMode();
  try {
    Symfony\Component\Console\Terminal::setColorMode(Symfony\Component\Console\Output\AnsiColorMode::Ansi24);
    Console::write('<fg=#ff87af;bg=#001122>X</>', 0, 0);
  } finally {
    Symfony\Component\Console\Terminal::setColorMode($mode);
  }
  $run = Console::presentationSnapshot()->textLayers[0]->runs[0];
  expect($run->foreground)->toEqual(PresentationColor::rgb(255, 135, 175))
    ->and($run->background)->toEqual(PresentationColor::rgb(0, 17, 34));
});

it('fails clearly on malformed canonical extended colour', function ($sequence) {
  Console::write("\e[{$sequence}mX", 0, 0);
  expect(fn() => Console::presentationSnapshot())->toThrow(InvalidArgumentException::class);
})->with(['38', '38;5', '38;5;256', '48;2;0;0', '38;2;1;;3', '48;3;1']);

it('retains exact colour identity and validates colour boundaries', function () {
  expect(PresentationColor::ansi16(15)->toArray())->toBe(['kind' => 'ansi16', 'index' => 15]);
  foreach ([fn() => PresentationColor::ansi16(16), fn() => PresentationColor::ansi16(-1),
    fn() => PresentationColor::ansi256(256), fn() => PresentationColor::rgb(0, -1, 0),
    fn() => PresentationColor::rgb(0, 0, 256)] as $invalid) {
    expect($invalid)->toThrow(InvalidArgumentException::class);
  }
});

it('validates strict runs layers frame limits and detached immutable lists', function () {
  foreach ([[-1, 0, 'a'], [0, -1, 'a'], [0, 0, "\xff"], [0, 0, "a\n"], [0, 0, "\t"], [0, 0, "\0"]] as $args) {
    expect(fn() => new PresentationTextRun(...$args))->toThrow(InvalidArgumentException::class);
  }
  $run = new PresentationTextRun(0, 0, 'X');
  expect($run->toArray())->toBe(['row' => 0, 'column' => 0, 'text' => 'X', 'foreground' => null, 'background' => null]);
  foreach (['', "\xff", str_repeat('a', 257)] as $id) {
    expect(fn() => new PresentationTextLayer($id, 0, []))->toThrow(InvalidArgumentException::class);
  }
  foreach ([-2147483649, 2147483648] as $layer) {
    expect(fn() => new PresentationTextLayer('x', $layer, []))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new PresentationTextLayer('x', 0, ['bad']))->toThrow(InvalidArgumentException::class);
  $runs = [&$run];
  $layer = new PresentationTextLayer('x', 0, $runs);
  $run = new PresentationTextRun(0, 0, 'Y');
  expect($layer->runs[0]->text)->toBe('X');
  expect(fn() => new StyledPresentationFrame(0, [$layer, $layer]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(-1))->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(0, array_map(fn($n) => new PresentationTextLayer((string)$n, 0, []), range(0, 64))))->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(0, [new PresentationTextLayer('x', 0, array_fill(0, 32769, $run))]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(0, [new PresentationTextLayer('x', 0, [new PresentationTextRun(0, 0, str_repeat('a', 524289))])]))->toThrow(InvalidArgumentException::class);
  foreach ([new PresentationTextRun(0, 8, ''), new PresentationTextRun(3, 0, ''), new PresentationTextRun(0, 7, 'XX')] as $outside) {
    expect(fn() => new ConsolePresentationSnapshot(8, 3, [new PresentationTextLayer('x', 0, [$outside])]))->toThrow(InvalidArgumentException::class);
  }
});

it('preserves sparse opaque blanks and underlay while excluding the Player only graphically', function () {
  Console::write('ABCDE', 0, 0);
  Console::withLayer('player', fn() => Console::write('@@@', 1, 0));
  Console::withLayer('hud', fn() => Console::write("\e[32mH", 1, 0), 1010);
  Console::withLayer('modal', fn() => Console::write('   ', 1, 0), 1020);
  $snapshot = Console::presentationSnapshot(['player']);
  expect(array_column($snapshot->textLayers, 'id'))->toBe(['world', 'hud', 'modal'])
    ->and($snapshot->textLayers[0]->runs[0]->text)->toBe('ABCDE   ')
    ->and($snapshot->textLayers[1]->runs[0]->foreground)->toEqual(PresentationColor::ansi16(2))
    ->and($snapshot->textLayers[2]->runs)->toHaveCount(1)
    ->and($snapshot->textLayers[2]->runs[0]->toArray())->toBe([
      'row' => 0, 'column' => 1, 'text' => '   ', 'foreground' => null, 'background' => null,
    ]);
  Console::withLayer('modal', fn() => Console::write('M', 1, 0), 1020);
  expect(Console::presentationSnapshot(['player'])->textLayers[2]->runs[0]->text)->toBe('M  ')
    ->and(Console::snapshot()->rows[0])->toBe('AM  E   ');
  Console::write('Z', 1, 0);
  $snapshot = Console::presentationSnapshot(['player']);
  expect(array_column($snapshot->textLayers, 'id'))->toBe(['world', 'modal'])
    ->and($snapshot->textLayers[0]->runs[0]->text)->toBe('AZCDE   ')
    ->and($snapshot->textLayers[1]->runs[0]->column)->toBe(2);
});

it('tracks identical glyph writes nested scopes and multirow wide provenance', function () {
  Console::write('........', 0, 0);
  Console::write('........', 0, 1);
  Console::withLayer('player', function () {
    Console::write("\e[1;31m界X", 1, 0);
    Console::write('..', 1, 1);
  });
  Console::withLayer('modal', function () {
    Console::withLayer('modal', fn() => Console::write('X', 3, 0), 1020);
    Console::write('Y', 5, 0);
  }, 1020);
  $all = Console::presentationSnapshot();
  expect($all->textLayers[1]->runs[0]->text)->toBe('界 X')
    ->and($all->textLayers[1]->runs[0]->foreground)->toEqual(PresentationColor::ansi16(9))
    ->and($all->textLayers[1]->runs[1]->text)->toBe('..')
    ->and($all->textLayers[2]->runs)->toHaveCount(2);
  expect(Console::presentationSnapshot(['player'])->textLayers[0]->runs[0]->text)->toBe('........')
    ->and(Console::snapshot()->rows[0])->toBe('.界 X.Y..');
});

it('uses the existing scalar fallback and preserves following cell alignment', function () {
  Console::write("\e[31m👨‍👩‍👧‍👦Z", 0, 0);
  $styled = implode('', array_map(fn($run) => $run->row === 0 ? $run->text : '', Console::presentationSnapshot()->textLayers[0]->runs));
  expect($styled)->toBe(Console::snapshot()->rows[0])->toStartWith('👨 Z');
  Console::write("\e[31me\u{301}Z", 0, 1);
  $runs = Console::presentationSnapshot()->textLayers[0]->runs;
  expect(array_values(array_filter($runs, fn($run) => $run->row === 1))[0]->text)->toBe('?Z');
});

it('rolls back layer state atomically and clears it on resize clear and recomposition', function () {
  Console::withLayer('old', fn() => Console::write('old', 0, 0), 1010);
  $before = Console::presentationSnapshot();
  expect(fn() => Console::recomposeFrame(function () {
    Console::withLayer('new', fn() => Console::write('bad', 0, 0), 1020);
    throw new RuntimeException('rollback');
  }))->toThrow(RuntimeException::class, 'rollback');
  expect(Console::presentationSnapshot())->toEqual($before);
  Console::recomposeFrame(fn() => Console::write('new', 0, 0));
  expect(Console::presentationSnapshot()->textLayers)->toHaveCount(1);
  Console::withLayer('old', fn() => Console::write('x', 0, 0));
  Console::clear();
  expect(Console::presentationSnapshot()->textLayers)->toHaveCount(1);
  Console::withLayer('old', fn() => Console::write('x', 0, 0));
  Console::syncDimensions(4, 2);
  expect(Console::presentationSnapshot()->textLayers)->toHaveCount(1);
});

it('queues colour-only changes transactionally and compares layer identity order priority and sprites', function () {
  $transport = new FakeRendererTransport();
  $presenter = new RendererPresentation(new RendererClient($transport), new RendererGridConfig(8, 3));
  Console::write("\e[31mX", 0, 0);
  $before = Console::getBuffer();
  expect($presenter->present(Console::presentationSnapshot()))->toBeTrue();
  Console::write("\e[32mX", 0, 0);
  expect(Console::getBuffer())->not->toBe($before);
  $transport->sendFailure = new RendererTransportException('pressure');
  expect(fn() => $presenter->present(Console::presentationSnapshot()))->toThrow(RendererTransportException::class);
  $transport->sendFailure = null;
  expect($presenter->present(Console::presentationSnapshot()))->toBeTrue()
    ->and($presenter->present(Console::presentationSnapshot()))->toBeFalse();
  Console::write("\e[32;44mX", 0, 0);
  expect($presenter->present(Console::presentationSnapshot()))->toBeTrue();
  foreach ([['a', 1010], ['b', 1010], ['b', 1020]] as [$id, $priority]) {
    Console::recomposeFrame(fn() => Console::withLayer($id, fn() => Console::write('X', 0, 0), $priority));
    expect($presenter->present(Console::presentationSnapshot()))->toBeTrue();
  }
  $sprite = new PresentationSprite('player', 'player.png', 0, 0, 1, 1, layer: 100);
  expect($presenter->present(Console::presentationSnapshot(), [$sprite]))->toBeTrue();
  expect(array_map(fn($message) => $message->payload['frame'], $transport->sent))->toBe(range(1, 7))
    ->and($transport->sent[0]->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($transport->sent[0]->payload)->not->toHaveKey('text');
});

it('reserves UI layers only in automatic runtime composition', function () {
  foreach ([-1, 1000, 2000] as $layer) {
    $sprite = new PresentationSprite('x', 'x.png', 0, 0, 1, 1, layer: $layer);
    expect(new StyledPresentationFrame(0, [], [$sprite])->sprites)->toBe([$sprite]);
    expect(fn() => PresentationLayerPolicy::assertWorldSprites([$sprite]))->toThrow(InvalidArgumentException::class);
  }
});
