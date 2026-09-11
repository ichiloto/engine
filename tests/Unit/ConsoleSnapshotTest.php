<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(24, 3);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->consoleState as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
});

it('snapshots empty and sparse buffers as a full fixed-size plain grid', function () {
  new ReflectionProperty(Console::class, 'buffer')->setValue(null, []);
  $empty = Console::snapshot();
  expect($empty->width)->toBe(24)->and($empty->height)->toBe(3)
    ->and($empty->rows)->toBe(array_fill(0, 3, str_repeat(' ', 24)));
  Console::write('ASCII', 2, 1);
  expect(Console::snapshot()->rows)->toBe([str_repeat(' ', 24), '  ASCII' . str_repeat(' ', 17), str_repeat(' ', 24)]);
});

it('strips styling only in the snapshot and leaves all Console and cursor state unchanged', function () {
  Console::write('<fg=#ff87af>@</>', 0, 0);
  Console::write("\033[32mX\033[0m", 2, 0);
  $before = new ReflectionClass(Console::class)->getStaticProperties();
  $cursor = new ReflectionClass(Cursor::class)->getStaticProperties();
  $output = ob_get_contents();
  $snapshot = Console::snapshot();
  expect($snapshot->rows[0])->toBe('@ X' . str_repeat(' ', 21))
    ->and(Console::getBuffer()[0])->toContain("\033[")
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($before)
    ->and(new ReflectionClass(Cursor::class)->getStaticProperties())->toBe($cursor)
    ->and(ob_get_contents())->toBe($output);
});

it('preserves the anchor continuation and following columns of a wide scalar', function ($symbol) {
  Console::write($symbol . '|END', 18, 0);
  $snapshot = Console::snapshot();
  expect(mb_strlen($snapshot->rows[0], 'UTF-8'))->toBe(24)
    ->and(mb_substr($snapshot->rows[0], 18, 1, 'UTF-8'))->toBe($symbol)
    ->and(mb_substr($snapshot->rows[0], 19, 1, 'UTF-8'))->toBe(' ')
    ->and(mb_substr($snapshot->rows[0], 20, 4, 'UTF-8'))->toBe('|END')
    ->and(TerminalText::displayWidth(Console::getBuffer()[0]))->toBe(24);
})->with(["\u{1F408}", "\u{754C}", "\u{1F6B6}"]);

it('uses a one-cell fallback for multi-scalar graphemes without collapsing reserved cells', function ($symbol, $width) {
  Console::write($symbol . '|', 2, 0);
  $row = Console::snapshot()->rows[0];
  expect(mb_substr($row, 2, $width + 1, 'UTF-8'))->toBe('?' . str_repeat(' ', $width - 1) . '|')
    ->and(mb_strlen($row, 'UTF-8'))->toBe(24);
})->with([["e\u{301}", 1], ["\u{2764}\u{FE0F}", 2], ["\u{1F5E1}", 2], ["\u{2764}\u{FE0E}", 1]]);

it('sanitizes control cells without leaking protocol controls or moving subsequent columns', function ($control) {
  new ReflectionProperty(Console::class, 'buffer')->setValue(null, ['A' . $control . 'B']);
  $snapshot = Console::snapshot();
  expect($snapshot->rows[0])->toBe('A?B' . str_repeat(' ', 21))
    ->and(preg_match('/\p{Cc}/u', $snapshot->rows[0]))->toBe(0);
})->with(["\0", "\033", "\t", "\r", "\n", "\x7F", "\u{85}"]);

it('rejects corrupt rows instead of silently coercing or blanking them', function ($row) {
  new ReflectionProperty(Console::class, 'buffer')->setValue(null, [$row]);
  expect(fn() => Console::snapshot())->toThrow(RuntimeException::class, 'Console row 0 must contain valid UTF-8 text.');
})->with(["A\xFFB", 123, false]);

it('applies the scalar fallback to composite emoji after existing terminal stabilization', function () {
  Console::write("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}|", 2, 0);
  $canonical = Console::charAt(2, 0);
  $expected = mb_strlen($canonical, 'UTF-8') === 1 ? $canonical : '?';
  expect(mb_substr(Console::snapshot()->rows[0], 2, 3, 'UTF-8'))->toBe($expected . ' |');
});

it('presents canonical Console cells while suppressing terminal-only style changes', function () {
  $transport = new FakeRendererTransport();
  $presentation = new RendererPresentation(new RendererClient($transport), new RendererGridConfig(24, 3));
  Console::write('<fg=red>@</>', 0, 0);
  Console::write("\u{1F408}|", 18, 0);
  expect($presentation->present(Console::snapshot()))->toBeTrue();
  Console::write('<fg=green>@</>', 0, 0);
  expect($presentation->present(Console::snapshot()))->toBeFalse()
    ->and($transport->sent)->toHaveCount(1)
    ->and($transport->sent[0]->payload['text'][0])->toBe('@' . str_repeat(' ', 17) . "\u{1F408} |   ");
});

it('rejects capture inside nested frames without changing frame depth or flushing', function () {
  Console::beginFrame();
  Console::beginFrame();
  Console::write('partial', 0, 0);
  $before = new ReflectionClass(Console::class)->getStaticProperties();
  expect(fn() => Console::snapshot())->toThrow(RuntimeException::class, 'active')
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($before)
    ->and(ob_get_contents())->toBe('');
  Console::endFrame();
  Console::endFrame();
  expect(Console::snapshot()->rows[0])->toStartWith('partial');
});

it('rejects snapshots during recomposition and retains the original completed frame', function () {
  Console::write('original', 0, 0);
  $before = Console::snapshot();
  expect(fn() => Console::recomposeFrame(function () {
    Console::write('partial', 0, 0);
    Console::snapshot();
  }))->toThrow(RuntimeException::class, 'recomposition');
  expect(Console::snapshot())->toEqual($before);
});

it('checks recomposition ownership independently of nested frame depth', function () {
  new ReflectionProperty(Console::class, 'isRecomposing')->setValue(null, true);
  expect(fn() => Console::snapshot())->toThrow(RuntimeException::class, 'recomposition');
});

it('captures full-width and resized rows without changing earlier snapshots', function () {
  Console::write(str_repeat('X', 24), 0, 0);
  $old = Console::snapshot();
  Console::syncDimensions(8, 2);
  Console::write('12345678', 0, 1);
  $new = Console::snapshot();
  expect($old->rows[0])->toBe(str_repeat('X', 24))->and($old->width)->toBe(24)
    ->and($new->width)->toBe(8)->and($new->height)->toBe(2)->and($new->rows)->toBe(['        ', '12345678']);
});

it('detaches caller references and forbids modifying snapshot cells or dimensions', function () {
  $row = 'abc';
  $snapshot = new ConsoleFrameSnapshot(3, 1, [&$row]);
  $row = 'xyz';
  expect($snapshot->rows)->toBe(['abc']);
  expect(function () use ($snapshot) { $snapshot->rows[0] = 'xyz'; })->toThrow(Error::class);
  expect(function () use ($snapshot) { $snapshot->width = 9; })->toThrow(Error::class);
});

it('validates manually supplied snapshot geometry and text', function ($width, $height, $rows) {
  expect(fn() => new ConsoleFrameSnapshot($width, $height, $rows))->toThrow(InvalidArgumentException::class);
})->with([[0, 1, ['']], [1, 0, []], [1, 2, ['x']], [2, 1, ['x']], [1, 1, [1 => 'x']],
  [1, 1, ["\n"]], [1, 1, ["\xFF"]], [1, 1, [12]]]);
