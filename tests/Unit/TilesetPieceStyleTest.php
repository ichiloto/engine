<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

function readStyledPiece(array $glyphs, array $extra = []): TilesetPiece
{
  return TilesetPiece::fromArray('synthetic', $extra + [
    'name' => 'Replaceable brush', 'layer' => 'fixtures', 'glyphs' => $glyphs,
  ], 'Synthetic tileset');
}

it('separates piece presentation from plain glyphs using the shared map style parser', function (string $row) {
  $piece = readStyledPiece([$row]);
  $layer = new MapLayer('fixtures', 1, false, 'synthetic', $row);
  expect($piece->glyphs)->toBe([['a', 'b', 'c']])
    ->and($piece->grid)->toBe($layer->grid)
    ->and($piece->glyphs)->toBe($layer->glyphs)
    ->and([$piece->width, $piece->height])->toBe([3, 1]);
  foreach ($piece->grid[0] as $x => $cell) {
    expect(TerminalText::visibleSymbols($cell))->toBe([$cell])
      ->and(TerminalText::stripAnsi($cell))->toBe($piece->glyphs[0][$x]);
  }
})->with([
  'formatter colours' => ['<fg=green>a</><fg=yellow>b</>c'],
  'nested styles' => ['<fg=green>a<options=bold>b</>c</>'],
  'truecolour and background' => ['<fg=#123456;bg=#abcdef>ab</>c'],
  'named style' => ['<info>ab</info>c'],
  'ANSI selective reset' => ["\e[31ma\e[1mb\e[22;39mc"],
  'ANSI indexed and RGB' => ["\e[38;5;82ma\e[38;2;100;80;50mb\e[0mc"],
]);

it('retains authored rows and tile footprint without adding style metadata to source', function () {
  $data = ['name' => 'Mixed brush', 'layer' => 'fixtures',
    'glyphs' => ['<fg=green> a </>', '<fg=yellow>b</>c '],
    'tiles' => ['objects' => ['0 12 0', '13 14 0']]];
  $original = $data;
  $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['B' => 'replaceable.png'],
    'pieces' => ['synthetic' => $data]]);
  $piece = $tileset->pieces['synthetic'];
  $text = implode("\n", $data['glyphs']);
  $source = MapGridSource::buildSource($text, 'SYNTHETIC');
  expect($data)->toBe($original)
    ->and($piece->glyphs)->toBe([[' ', 'a', ' '], ['b', 'c', ' ']])
    ->and($piece->grid)->toBe(MapLayer::parseGrid(implode("\n", $data['glyphs'])))
    ->and($piece->tiles)->toBe(['objects' => [['0', '12', '0'], ['13', '14', '0']]])
    ->and([$piece->width, $piece->height])->toBe([3, 2])
    ->and(array_keys($data))->toBe(['name', 'layer', 'glyphs', 'tiles'])
    ->and(MapGridSource::parseSource($source, 'synthetic'))->toBe($text)
    ->and(MapLayer::parseGrid(MapGridSource::parseSource($source, 'synthetic')))->toBe($piece->grid);
  $grid = $piece->grid;
  $grid[0][1] = 'changed';
  expect($piece->glyphs[0][1])->toBe('a')->and($piece->grid[0][1])->not->toBe('changed');
});

it('retains single-cell Unicode graphemes in both styled and plain geometry', function () {
  $row = "<fg=green>e\u{0301}</>x";
  $piece = readStyledPiece([$row]);
  $layer = new MapLayer('fixtures', 1, false, 'synthetic', $row);
  expect($piece->glyphs)->toBe($layer->glyphs)
    ->and($piece->grid)->toBe($layer->grid)
    ->and(array_map(TerminalText::symbolCount(...), $piece->glyphs[0]))->toBe([1, 1])
    ->and([$piece->width, $piece->height])->toBe([2, 1]);
});

it('leaves unstyled cells and connected shapes plain for existing brush fallback', function () {
  $piece = readStyledPiece([' ab', 'cd ']);
  $direct = new TilesetPiece('synthetic', 'Plain', 'fixtures', [[' ', 'a', 'b'], ['c', 'd', ' ']]);
  $connected = readStyledPiece(['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], ['connects' => 'lines']);
  expect($piece->grid)->toBe($piece->glyphs)
    ->and($direct->glyphs)->toBe($piece->glyphs)
    ->and($direct->grid)->toBe($piece->grid)
    ->and($connected->shapeGrid)->toBe($connected->shapes)
    ->and($connected->grid)->toBe([['+']]);
});

it('normalizes styled constructor cells without changing its existing argument contract', function () {
  $piece = new TilesetPiece(id: 'synthetic', name: 'Styled', layer: 'fixtures',
    glyphs: [['<fg=green>a</>', 'b']], shapes: ['corner' => '<fg=yellow>+</>']);
  expect($piece->glyphs)->toBe([['a', 'b']])
    ->and($piece->grid)->toBe([TerminalText::visibleSymbols('<fg=green>a</>b')])
    ->and($piece->shapes)->toBe(['corner' => '+'])
    ->and($piece->shapeGrid)->toBe(['corner' => TerminalText::firstSymbol('<fg=yellow>+</>')]);
});

it('keeps coloured connected shapes and recoloured cells in the same plain membership', function () {
  $authored = ['horizontal' => '<fg=green>-</>', 'vertical' => '<fg=yellow>|</>', 'corner' => '<fg=red>+</>'];
  $piece = readStyledPiece($authored, ['connects' => 'lines', 'tiles' => ['objects' => [
    'horizontal' => '12', 'vertical' => '13', 'corner' => '14',
  ]]]);
  expect($piece->shapes)->toBe(['horizontal' => '-', 'vertical' => '|', 'corner' => '+'])
    ->and($piece->glyphs)->toBe([['+']])
    ->and($piece->grid)->toBe([[$piece->shapeGrid['corner']]])
    ->and($piece->shapeTiles)->toBe(['objects' => ['horizontal' => '12', 'vertical' => '13', 'corner' => '14']]);
  foreach ($authored as $shape => $text) {
    expect($piece->shapeGrid[$shape])->toBe(TerminalText::firstSymbol($text))
      ->and($piece->isMember($text))->toBeTrue()
      ->and($piece->isMember($piece->shapeGrid[$shape]))->toBeTrue()
      ->and($piece->isMember('<fg=blue>' . $piece->shapes[$shape] . '</>'))->toBeTrue();
  }
  expect($piece->isMember('<fg=green> </>'))->toBeFalse()
    ->and($piece->isMember('<fg=green>x</>'))->toBeFalse();
  for ($mask = 0; $mask < 16; $mask++) {
    $north = (bool) ($mask & 1); $east = (bool) ($mask & 2);
    $south = (bool) ($mask & 4); $west = (bool) ($mask & 8);
    $expected = ($east || $west) && !($north || $south) ? 'horizontal'
      : (($north || $south) && !($east || $west) ? 'vertical' : 'corner');
    expect($piece->getLineShape($north, $east, $south, $west))->toBe($expected);
  }
});

it('rejects colour as a distinction between connected shapes', function (string $first, string $second) {
  expect(fn() => readStyledPiece(['horizontal' => $first, 'vertical' => $second, 'corner' => '+'], ['connects' => 'lines']))
    ->toThrow(InvalidArgumentException::class, 'different glyph for each shape');
})->with([
  'markup' => ['<fg=green>-</>', '<fg=red>-</>'],
  'ANSI and plain' => ["\e[31m-\e[0m", '-'],
]);

it('rejects styled blank connected shapes', function (string $blank) {
  expect(fn() => readStyledPiece(['horizontal' => $blank, 'vertical' => '|', 'corner' => '+'], ['connects' => 'lines']))
    ->toThrow(InvalidArgumentException::class, 'one visible character');
})->with(['markup' => ['<fg=green> </>'], 'ANSI' => ["\e[31m \e[0m"]]);

it('validates styled rows by plain terminal-cell geometry', function (array $rows) {
  expect(fn() => readStyledPiece($rows))->toThrow(InvalidArgumentException::class);
})->with([
  'ragged' => [['<fg=green>ab</>', '<fg=yellow>c</>']],
  'empty styled row' => [['<fg=green></>']],
  'wide glyph' => [["<fg=green>\u{754c}</>"]],
  'zero width' => [["<fg=green>\u{0301}</>"]],
  'line break' => [["<fg=green>a\nb</>"]],
]);

it('stamps styled cells through the existing map contract without changing collision or space ownership', function () {
  $piece = readStyledPiece(['<fg=green> a </>', '<fg=yellow>b</>c ']);
  $styled = new MapLayer('fixtures', 1, false, 'styled',
    implode("\n", array_map(static fn(array $row): string => implode('', $row), $piece->grid)));
  $plain = new MapLayer('fixtures', 1, false, 'plain',
    implode("\n", array_map(static fn(array $row): string => implode('', $row), $piece->glyphs)));
  $terrain = new MapLayer('terrain', 0, false, 'terrain', "...\n...");
  $styledSet = new MapLayerSet([$terrain, $styled]);
  $plainSet = new MapLayerSet([$terrain, $plain]);
  $types = ['.' => CollisionType::NONE, 'a' => CollisionType::SOLID, 'b' => CollisionType::COUNTER,
    'c' => CollisionType::PASS_THROUGH];
  expect($styled->grid)->toBe($piece->grid)->and($styled->glyphs)->toBe($piece->glyphs)
    ->and($styledSet->gameplayOwners)->toBe($plainSet->gameplayOwners)
    ->and($styledSet->gameplayOwners)->toBe([[0, 1, 0], [1, 1, 0]])
    ->and(MapCollisionResolver::resolveLayers($styledSet, $types))->toBe(MapCollisionResolver::resolveLayers($plainSet, $types))
    ->and(MapCollisionResolver::resolveLayers($styledSet, $types))->toBe([
      [CollisionType::NONE->value, CollisionType::SOLID->value, CollisionType::NONE->value],
      [CollisionType::COUNTER->value, CollisionType::NONE->value, CollisionType::NONE->value],
    ]);
});
