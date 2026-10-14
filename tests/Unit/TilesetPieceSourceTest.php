<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

it('retains independently authored source cells before display formatting', function (string $row, array $cells) {
  $data = ['name' => 'Synthetic brush', 'layer' => 'fixtures', 'glyphs' => [$row]];
  $original = $data;
  $piece = TilesetPiece::fromArray('synthetic', $data, 'Synthetic');
  expect(TerminalText::getSourceSymbols($row))->toBe($cells)
    ->and($piece->getSourceGrid())->toBe([$cells])
    ->and($data)->toBe($original)
    ->and($piece->grid)->toBe(MapLayer::parseGrid($row))
    ->and([$piece->width, $piece->height])->toBe([count($cells), 1]);
  foreach ($cells as $x => $source) {
    expect(array_map(Normalizer::normalize(...), TerminalText::visibleSymbols($source)))
      ->toBe([Normalizer::normalize($piece->grid[0][$x])])
      ->and(TerminalText::getSourceSymbols($source))->toBe([$source]);
  }
})->with([
  'row spanning colour' => ['<fg=green>ab</>c', ['<fg=green>a</>', '<fg=green>b</>', 'c']],
  'named closing spelling' => ['<info>ab</info>', ['<info>a</info>', '<info>b</info>']],
  'nested scopes' => ['<fg=red>a<options=bold>b</>c</fg=red>',
    ['<fg=red>a</fg=red>', '<fg=red><options=bold>b</></fg=red>', '<fg=red>c</fg=red>']],
  'tag case and hex spelling' => ['<fg=#AbCdEf;bg=BLACK>ab</fg=#abcdef;bg=black>',
    ['<fg=#AbCdEf;bg=BLACK>a</fg=#abcdef;bg=black>', '<fg=#AbCdEf;bg=BLACK>b</fg=#abcdef;bg=black>']],
  'adjacent independent colours' => ['<fg=green>a</><fg=yellow>b</>', ['<fg=green>a</>', '<fg=yellow>b</>']],
  'styled and plain spaces' => ['<fg=green> a</> ', ['<fg=green> </>', '<fg=green>a</>', ' ']],
  'raw Unicode grapheme bytes' => ["<fg=green>e\u{0301}x</>", ["<fg=green>e\u{0301}</>", '<fg=green>x</>']],
  'unstyled graphemes' => ["e\u{0301}x", ["e\u{0301}", 'x']],
  'literal less than' => ['a<b', ['a', '<', 'b']],
  'implicit nested closure' => ['<fg=green>a<options=bold>b</fg=green>c',
    ['<fg=green>a</fg=green>', '<fg=green><options=bold>b</></fg=green>', 'c']],
  'authored ANSI spelling' => ["\e[031mab\e[039m", ["\e[031ma\e[039m", "\e[031mb\e[039m"]],
  'empty scopes on one cell' => ['<fg=green></>a', ['<fg=green></>a']],
  'unstyled grapheme after markup' => ["<fg=green>x</>e\u{0301}", ['<fg=green>x</>', "e\u{0301}"]],
  'unrecognised literal tag' => ['<unknown>x', ['<', 'u', 'n', 'k', 'n', 'o', 'w', 'n', '>', 'x']],
  'ANSI ending in plain brush fallback' => ["\e[031ma\e[001mb\e[022;039mc",
    ["\e[031ma\e[001m\e[022;039m", "\e[031m\e[001mb\e[022;039m", 'c']],
]);

it('provides exact source parts for a consumer without a second style parser', function () {
  $source = "<fg=#AbCdEf;bg=BLACK>e\u{0301}<options=bold>x</options=bold></fg=#abcdef;bg=black> ";
  $expected = [
    ['symbol' => "e\u{0301}", 'prefix' => '<fg=#AbCdEf;bg=BLACK>', 'suffix' => '</fg=#abcdef;bg=black>'],
    ['symbol' => 'x', 'prefix' => '<fg=#AbCdEf;bg=BLACK><options=bold>', 'suffix' => '</options=bold></fg=#abcdef;bg=black>'],
    ['symbol' => ' ', 'prefix' => '', 'suffix' => ''],
  ];
  $cells = TerminalText::parseSourceCells($source);
  expect($cells)->toBe($expected);
  $text = implode('', array_map(static fn(array $cell): string => $cell['prefix'] . $cell['symbol'] . $cell['suffix'], $cells));
  $php = MapGridSource::buildSource($text, 'SOURCE_PARTS');
  expect(MapGridSource::parseSource($php, 'synthetic'))->toBe($text)
    ->and(TerminalText::parseSourceCells(MapGridSource::parseSource($php, 'synthetic')))->toBe($expected)
    ->and(MapLayer::parseGrid($text))->toBe(MapLayer::parseGrid($source));
  $cells[0]['prefix'] = '<fg=blue>';
  expect($expected[0]['prefix'])->toBe('<fg=#AbCdEf;bg=BLACK>')
    ->and($cells[1]['prefix'])->toBe('<fg=#AbCdEf;bg=BLACK><options=bold>');
});

it('keeps connected authored shape source separate from display and plain identity', function () {
  $glyphs = ['horizontal' => '<fg=green>-</>', 'vertical' => '<info>|</info>',
    'corner' => '<fg=#AbCdEf;bg=BLACK>+</fg=#abcdef;bg=black>'];
  $piece = TilesetPiece::fromArray('synthetic', ['name' => 'Synthetic fence', 'layer' => 'fixtures',
    'connects' => 'lines', 'glyphs' => $glyphs, 'tiles' => ['objects' => '12']], 'Synthetic');
  expect($piece->getSourceShapeGrid())->toBe($glyphs)
    ->and($piece->getSourceGrid())->toBe([[$glyphs['corner']]])
    ->and($piece->shapes)->toBe(['horizontal' => '-', 'vertical' => '|', 'corner' => '+'])
    ->and($piece->glyphs)->toBe([['+']])
    ->and($piece->shapeGrid)->toBe(array_map(TerminalText::firstSymbol(...), $glyphs))
    ->and($piece->shapeTiles)->toBe(['objects' => ['horizontal' => '12', 'vertical' => '12', 'corner' => '12']]);
  foreach ($piece->getSourceShapeGrid() as $shape => $source) {
    expect($piece->isMember($source))->toBeTrue()
      ->and(TerminalText::visibleSymbols($source))->toBe([$piece->shapeGrid[$shape]]);
  }
});

it('refuses unsafe source splitting without narrowing runtime loading or display', function (string $row) {
  $piece = TilesetPiece::fromArray('synthetic', ['name' => 'Synthetic', 'layer' => 'fixtures', 'glyphs' => [$row]], 'Synthetic');
  $display = $piece->grid;
  $glyphs = $piece->glyphs;
  expect(fn() => $piece->getSourceGrid())->toThrow(InvalidArgumentException::class, 'Keep this source intact; no cells were produced')
    ->and($piece->grid)->toBe($display)->and($piece->glyphs)->toBe($glyphs)
    ->and($piece->grid)->toBe(MapLayer::parseGrid($row));
})->with([
  'cross-context ANSI and markup' => ["\e[1;31ma<info>x</info>b"],
  'formatter escaped tag literal' => ['\\<fg=green>ab'],
]);

it('materializes missing closing boundaries without leaking formatter state into other consumers', function () {
  $property = new ReflectionProperty(TerminalText::class, 'formatter');
  $original = $property->getValue();
  $formatter = new Symfony\Component\Console\Formatter\OutputFormatter(true);
  $formatter->getStyleStack()->push(new Symfony\Component\Console\Formatter\OutputFormatterStyle('red'));
  $property->setValue(null, $formatter);
  try {
    expect(TerminalText::getSourceSymbols('<fg=green>ab'))->toBe(['<fg=green>a</>', '<fg=green>b</>'])
      ->and(TerminalText::getSourceSymbols('<fg=green>a'))->toBe(['<fg=green>a</>'])
      ->and(TerminalText::getSourceSymbols("\e[031ma"))->toBe(["\e[031ma\e[0m"])
      ->and($property->getValue())->toBe($formatter);
    expect(fn() => TerminalText::getSourceSymbols('\\<fg=green>ab'))->toThrow(InvalidArgumentException::class)
      ->and($property->getValue())->toBe($formatter)
      ->and($formatter->format('x</>'))->toBe("\e[31mx\e[39m");
  } finally {
    $property->setValue(null, $original);
  }
});

it('preserves source-cell constructor inputs and plain brush fallback without new authored fields', function () {
  $source = [['<info>a</info>', 'b', ' ']];
  $shape = ['corner' => '<fg=green>+</>'];
  $piece = new TilesetPiece('synthetic', 'Synthetic', 'fixtures', $source, shapes: $shape);
  expect($piece->getSourceGrid())->toBe($source)->and($piece->getSourceShapeGrid())->toBe($shape)
    ->and($piece->glyphs)->toBe([['a', 'b', ' ']])
    ->and($piece->getSourceGrid()[0][1])->toBe($piece->glyphs[0][1])
    ->and($piece->getSourceGrid()[0][2])->toBe(' ');
  $copy = $piece->getSourceGrid();
  $copy[0][0] = 'changed';
  expect($piece->getSourceGrid())->toBe($source);
});

it('writes exact independently authored markup through a synthetic map source consumer', function () {
  $rows = ['<fg=green> a</> ', '<info>b</info><fg=yellow>c</> '];
  $piece = TilesetPiece::fromArray('synthetic', ['name' => 'Synthetic', 'layer' => 'fixtures', 'glyphs' => $rows], 'Synthetic');
  $expected = "<fg=green> </><fg=green>a</> \n<info>b</info><fg=yellow>c</> ";
  $text = implode("\n", array_map(static fn(array $row): string => implode('', $row), $piece->getSourceGrid()));
  $source = MapGridSource::buildSource($text, 'SYNTHETIC', "// Keep synthetic source cells.\n");
  expect($text)->toBe($expected)->and(str_contains($text, "\e"))->toBeFalse()
    ->and(MapGridSource::parseSource($source, 'synthetic'))->toBe($expected)
    ->and(MapGridSource::buildSource(MapGridSource::parseSource($source, 'synthetic'), 'SYNTHETIC',
      "// Keep synthetic source cells.\n"))->toBe($source);
  $placed = new MapLayer('fixtures', 1, false, 'synthetic', MapGridSource::parseSource($source, 'synthetic'));
  $original = new MapLayer('fixtures', 1, false, 'synthetic', implode("\n", $rows));
  $terrain = new MapLayer('terrain', 0, false, 'terrain', "...\n...");
  $types = ['.' => CollisionType::NONE, 'a' => CollisionType::SOLID, 'b' => CollisionType::COUNTER,
    'c' => CollisionType::PASS_THROUGH];
  expect($placed->grid)->toBe($piece->grid)->and($placed->glyphs)->toBe($piece->glyphs)
    ->and(MapCollisionResolver::resolveLayers(new MapLayerSet([$terrain, $placed]), $types))
    ->toBe(MapCollisionResolver::resolveLayers(new MapLayerSet([$terrain, $original]), $types));
});
