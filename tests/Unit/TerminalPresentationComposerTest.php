<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalPresentationComposer;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

it('keeps heavily styled rows on their visible grid without shrinking them to escape-code length', function () {
  $line = str_repeat("\e[1;33m_\e[0m", 135);
  $lines = [$line, "\e[31mD\e[0mjin"];
  $canvas = new TerminalPresentationComposer()->createCanvasFromLines($lines, new RendererGridConfig(135, 30, 10, 24));
  $runs = $canvas->textLayers[0]->runs;
  expect(strlen($line))->toBeGreaterThan(1000)
    ->and($canvas->width)->toBe(1350)->and($canvas->height)->toBe(720)
    ->and($runs[0]->text)->toBe(str_repeat('_', 135))
    ->and($runs[0]->foreground)->toEqual(PresentationColor::ansi16(11))
    ->and($runs[1]->row)->toBe(1)->and($runs[1]->column)->toBe(0)
    ->and($runs[1]->foreground)->toEqual(PresentationColor::ansi16(1))
    ->and($runs[2]->text)->toBe('jin')->and($runs[2]->column)->toBe(1)
    ->and($runs[2]->foreground)->toBeNull()
    ->and($lines[0])->toBe($line);
  foreach ($runs as $run) {
    expect($run->text)->not->toContain("\e");
    $run->assertFits(135, 30);
  }
});

it('shares the exact Console projection for styled Unicode, blanks and following-cell alignment', function ($line) {
  $state = new ReflectionClass(Console::class)->getStaticProperties();
  ob_start();
  try {
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
      'output' => null, 'terminalOutputStream' => null] as $name => $value) {
      new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
    Console::syncDimensions(8, 1);
    Console::setLayerTracking(true);
    Console::write($line, 0, 0);
    $composer = new TerminalPresentationComposer();
    $runs = $composer->createRunsFromLines([str_pad($line, strlen($line) + 8, ' ')], new RendererGridConfig(8, 1));
    expect($runs)->toEqual(Console::presentationSnapshot()->textLayers[0]->runs)
      ->and($composer->createRunsForRow(0, NormalizedRow::fromText($line . str_repeat(' ', 8))->clippedCells(8)))
      ->toEqual($runs);
  } finally {
    ob_end_clean();
    foreach ($state as $name => $value) {
      new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
  }
})->with([
  'background and independent reset' => "\e[31;44mX\e[39m \e[49mZ",
  'indexed and true color' => "\e[38;5;42mA\e[38;2;7;8;9mB\e[0mZ",
  'wide glyph' => "\e[1;31m界Z\e[0m",
  'scalar fallback' => "\e[32me\u{301}Z\e[0m",
  'wide multi-scalar fallback' => "\e[33m👨‍👩‍👧‍👦Z\e[0m",
]);

it('preserves sparse cell gaps and continuation color without creating opaque gap text', function () {
  $runs = new TerminalPresentationComposer()->createRuns([2 => [
    4 => NormalizedRow::CONTINUATION, 1 => "\e[31m界\e[0m", 2 => NormalizedRow::CONTINUATION,
    7 => 'Z',
  ]]);
  expect($runs[0]->row)->toBe(2)->and($runs[0]->column)->toBe(1)
    ->and($runs[0]->text)->toBe('界 ')->and($runs[0]->foreground)->toEqual(PresentationColor::ansi16(1))
    ->and($runs[1]->column)->toBe(4)->and($runs[1]->text)->toBe(' ')
    ->and($runs[1]->foreground)->toBeNull()
    ->and($runs[2]->column)->toBe(7)->and($runs[2]->text)->toBe('Z');
});

it('clips whole glyphs at the preview edge and never splits their footprint', function () {
  $runs = new TerminalPresentationComposer()->createRunsFromLines(["\e[31mA界Z\e[0m"], new RendererGridConfig(2, 1));
  expect($runs)->toHaveCount(1)->and($runs[0]->text)->toBe('A');
});

it('rejects malformed source lines and oversized row lists before producing a canvas', function ($lines) {
  expect(fn() => new TerminalPresentationComposer()->createCanvasFromLines($lines, new RendererGridConfig(8, 1)))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'non-list' => [[1 => 'X']],
  'too many rows' => [['X', 'Y']],
  'invalid UTF-8' => [["\xff"]],
  'non-string' => [[42]],
  'malformed extended color' => [["\e[38;5;256mX"]],
]);
