<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Field\FieldPieceEffects;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/** Synthetic source data exercises the same canonical tile parsing as authored maps. */
function createFieldPieceEffectsGraphics(array $pieces, array $sources): MapGraphics
{
  $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic',
    'sheets' => ['B' => 'Graphics/synthetic.png'], 'pieces' => $pieces]);
  $layers = [];
  foreach ($sources as $name => $text) {
    $layers[] = new MapTileLayer($name, count($layers), $name, $text);
  }
  return new MapGraphics($tileset, $layers);
}

it('ignores effectless pieces and empty or all-wildcard stencils', function () {
  $pieces = [
    'plain' => new TilesetPiece('plain', 'Plain', 'fixtures', [['?']], ['props' => [['56']]]),
    'empty' => new TilesetPiece('empty', 'Empty', 'fixtures', [['?']], effect: 'glow'),
    'zeros' => new TilesetPiece('zeros', 'Zeros', 'fixtures', [['?']], ['props' => [['0']]], effect: 'glow'),
    'empty-rows' => new TilesetPiece('empty-rows', 'Empty rows', 'fixtures', [['?']], ['props' => []], effect: 'glow'),
  ];
  $graphics = new MapGraphics(new Tileset('synthetic', 'Synthetic', [], pieces: $pieces),
    [new MapTileLayer('props', 0, 'props', '56 0 56')]);
  expect(FieldPieceEffects::find($graphics))->toBe([]);
});

it('matches canonical integer identities without changing the string entry contract or its inputs', function () {
  $graphics = createFieldPieceEffectsGraphics([
    'beacon' => ['name' => 'Beacon', 'layer' => 'fixtures', 'glyphs' => ['?'],
      'tiles' => ['props' => ['0056']], 'effect' => 'glow'],
  ], ['props' => '000 0056 57 56']);
  $originalTiles = $graphics->layers[0]->readTiles();
  $originalStencil = $graphics->tileset->pieces['beacon']->tiles;
  $expected = [
    ['id' => 'piece-beacon-1-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 1, 'y' => 0]]],
    ['id' => 'piece-beacon-3-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 3, 'y' => 0]]],
  ];
  expect(FieldPieceEffects::find($graphics))->toBe($expected)
    ->and(FieldPieceEffects::find($graphics))->toBe($expected)
    ->and($graphics->layers[0]->readTiles())->toBe($originalTiles)->toBe([[0, 56, 57, 56]])
    ->and($graphics->tileset->pieces['beacon']->tiles)->toBe($originalStencil)->toBe(['props' => [['56']]])
    ->and($graphics->layers[0]->getEntries())->toBe([['0', '56', '57', '56']]);
});

it('requires every nonzero cell across layers while leaving zero cells as wildcards', function () {
  $graphics = createFieldPieceEffectsGraphics([
    'beacon' => ['name' => 'Beacon', 'layer' => 'fixtures', 'glyphs' => ['???', '???'],
      'tiles' => ['base' => ['56 0 57', '0 58 0'], 'top' => ['0 59 0', '60 0 61']], 'effect' => 'glow'],
  ], [
    'base' => "56 99 57 0 56 99 57\n99 58 99 0 99 58 99",
    'top' => "99 59 99 0 99 59 99\n60 99 61 0 60 99 62",
  ]);
  expect(FieldPieceEffects::find($graphics))->toBe([
    ['id' => 'piece-beacon-0-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 1, 'y' => 1]]],
  ]);
});

it('preserves piece order, row order, overlaps and bottom-centre anchors', function () {
  $graphics = createFieldPieceEffectsGraphics([
    'wide' => ['name' => 'Wide', 'layer' => 'fixtures', 'glyphs' => ['??'],
      'tiles' => ['props' => ['56 56']], 'effect' => 'wide-glow'],
    'single' => ['name' => 'Single', 'layer' => 'fixtures', 'glyphs' => ['?'],
      'tiles' => ['props' => ['56']], 'effect' => 'small-glow'],
  ], ['props' => "56 56 56\n56 56 0"]);
  $effects = FieldPieceEffects::find($graphics);
  expect(array_column($effects, 'id'))->toBe(['piece-wide-0-0', 'piece-wide-1-0', 'piece-wide-0-1',
    'piece-single-0-0', 'piece-single-1-0', 'piece-single-2-0', 'piece-single-0-1', 'piece-single-1-1'])
    ->and(array_column($effects, 'effect'))->toBe(['wide-glow', 'wide-glow', 'wide-glow',
      'small-glow', 'small-glow', 'small-glow', 'small-glow', 'small-glow'])
    ->and(array_column($effects, 'anchor'))->toBe(array_map(static fn(array $cell): array => ['cell' => $cell], [
      ['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0], ['x' => 0, 'y' => 1],
      ['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0], ['x' => 0, 'y' => 1], ['x' => 1, 'y' => 1],
    ]));
});

it('does not match nonzero cells outside ragged rows or across empty rows', function () {
  $graphics = createFieldPieceEffectsGraphics([
    'across' => ['name' => 'Across', 'layer' => 'fixtures', 'glyphs' => ['??'],
      'tiles' => ['props' => ['56 57']], 'effect' => 'glow'],
    'down' => ['name' => 'Down', 'layer' => 'fixtures', 'glyphs' => ['?', '?'],
      'tiles' => ['props' => ['56', '57']], 'effect' => 'glow'],
  ], ['props' => "\n56\n\n56 57\n56"]);
  expect(FieldPieceEffects::find($graphics))->toBe([
    ['id' => 'piece-across-0-3', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 0, 'y' => 3]]],
  ]);
});

it('does not require bounds or layers for wildcard cells but retains the first-layer candidate domain', function () {
  $graphics = createFieldPieceEffectsGraphics([
    'edge' => ['name' => 'Edge', 'layer' => 'fixtures', 'glyphs' => ['??', '??'],
      'tiles' => ['props' => ['56 0', '0 0'], 'absent' => ['0 0', '0 0']], 'effect' => 'glow'],
    'missing-cell' => ['name' => 'Missing cell', 'layer' => 'fixtures', 'glyphs' => ['?'],
      'tiles' => ['props' => ['56'], 'absent' => ['57']], 'effect' => 'glow'],
    'missing-first' => ['name' => 'Missing first', 'layer' => 'fixtures', 'glyphs' => ['?'],
      'tiles' => ['absent' => ['0'], 'props' => ['56']], 'effect' => 'glow'],
    'blank-first' => ['name' => 'Blank first', 'layer' => 'fixtures', 'glyphs' => ['?'],
      'tiles' => ['blank' => ['0'], 'props' => ['56']], 'effect' => 'glow'],
  ], ['props' => '56', 'blank' => '0 0']);
  expect(FieldPieceEffects::find($graphics))->toBe([
    ['id' => 'piece-edge-0-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 0, 'y' => 1]]],
    ['id' => 'piece-blank-first-0-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 0, 'y' => 0]]],
  ]);
});

it('uses the last map layer of a given name without depending on drawing order', function () {
  $graphics = createFieldPieceEffectsGraphics([
    'beacon' => ['name' => 'Beacon', 'layer' => 'fixtures', 'glyphs' => ['?'],
      'tiles' => ['props' => ['56']], 'effect' => 'glow'],
  ], ['props' => '56 0']);
  $graphics = new MapGraphics($graphics->tileset, [...$graphics->layers,
    new MapTileLayer('props', 1, 'replacement', '0 56')]);
  expect(FieldPieceEffects::find($graphics))->toBe([
    ['id' => 'piece-beacon-1-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 1, 'y' => 0]]],
  ]);
});

it('keeps matching allocation bounded under 128 MB regardless of unrelated layer area', function (string $mode) {
  // Isolate the measurement from PHPUnit and fixture construction; no native host or assets are used.
  $probe = <<<'PHP'
  require $argv[1] . '/vendor/autoload.php';
  $pieces = [];
  if ($argv[2] !== 'none') {
    $pieces['beacon'] = new \Ichiloto\Engine\Rendering\Tilesets\TilesetPiece('beacon', 'Beacon', 'fixtures',
      [['?']], ['props' => [['57']]], effect: $argv[2] === 'active' ? 'glow' : null);
  }
  $tileset = new \Ichiloto\Engine\Rendering\Tilesets\Tileset('synthetic', 'Synthetic', [], pieces: $pieces);
  \Ichiloto\Engine\Animations\Field\FieldPieceEffects::find(new \Ichiloto\Engine\Field\MapGraphics($tileset, []));
  $row = implode(' ', array_fill(0, 256, '56'));
  $text = '57' . substr($row, 2) . "\n" . implode("\n", array_fill(0, 255, $row));
  $layers = [];
  for ($index = 0; $index < 16; $index++) {
    $name = $index === 0 ? 'props' : 'unrelated-' . $index;
    $layers[] = new \Ichiloto\Engine\Field\MapTileLayer($name, $index, $name, $text);
  }
  $graphics = new \Ichiloto\Engine\Field\MapGraphics($tileset, $layers);
  unset($text, $row, $layers, $tileset, $pieces);
  memory_reset_peak_usage();
  $before = memory_get_usage();
  $effects = \Ichiloto\Engine\Animations\Field\FieldPieceEffects::find($graphics);
  echo json_encode(['additionalPeakBytes' => memory_get_peak_usage() - $before,
    'peakBytes' => memory_get_peak_usage(true), 'memoryLimit' => ini_get('memory_limit'),
    'effects' => $effects], JSON_THROW_ON_ERROR);
  PHP;
  $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=10',
    '-d', 'error_reporting=-1', '-r', $probe, dirname(__DIR__, 2), $mode],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) { throw new RuntimeException('Could not start field piece memory probe.'); }
  try {
    $deadline = hrtime(true) + 15_000_000_000;
    do {
      $status = proc_get_status($process);
      if (!$status['running']) { break; }
      if (hrtime(true) >= $deadline) { throw new RuntimeException('Field piece memory probe exceeded 15 seconds.'); }
      usleep(10_000);
    } while (true);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
  } finally {
    if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($process);
  }
  expect($status['exitcode'])->toBe(0, $stderr)->and($stderr)->toBe('');
  $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
  expect($result['memoryLimit'])->toBe('128M')
    ->and($result['peakBytes'])->toBeLessThan(128 * 1024 * 1024)
    ->and($result['additionalPeakBytes'])->toBeLessThan(1024 * 1024)
    ->and($result['effects'])->toBe($mode === 'active' ? [
      ['id' => 'piece-beacon-0-0', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 0, 'y' => 0]]],
    ] : []);
})->with(['no pieces' => 'none', 'effectless pieces' => 'effectless', 'one effect-bearing piece' => 'active']);
