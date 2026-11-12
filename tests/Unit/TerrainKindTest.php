<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\EncounterManager;
use Ichiloto\Engine\Field\MapAtlasTerrain;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapTerrainKindRule;
use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Terrain kinds: a synthetic world tileset whose kinds are painted on a
 * `terrain` gameplay layer, with ground autotiles on `ground`, features on
 * `features` and water on `water`. Nothing here reads production content.
 */

/** @return array<string, mixed> A synthetic world tileset's data, with overrides. */
function terrainKindTilesetData(array $kinds = [], array $extra = []): array
{
  return [
    'name' => 'Synthetic world',
    'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png', 'B' => 'Graphics/B.png'],
    'pieces' => ['sign' => ['name' => 'Sign', 'layer' => 'terrain', 'glyphs' => ['i'], 'tiles' => ['features' => ['4']]]],
    'kinds' => $kinds + [
      'meadow' => ['name' => 'Meadow', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>', 'art' => ['layer' => 'ground', 'tile' => 2816],
        'passage' => 'walk', 'encounters' => 'meadow', 'atlas' => 'ground', 'keeps' => ['substrate']],
      'path' => ['name' => 'Path', 'layer' => 'terrain', 'glyph' => '<fg=yellow>=</>', 'art' => ['layer' => 'ground', 'tile' => 2864],
        'passage' => 'walk', 'atlas' => 'road'],
      'grove' => ['name' => 'Grove', 'layer' => 'terrain', 'glyph' => '<fg=green>♣</>', 'ground' => 'meadow',
        'art' => ['layer' => 'features', 'tile' => 2912], 'passage' => 'walk', 'encounters' => 'grove', 'atlas' => 'vegetation'],
      'peaks' => ['name' => 'Peaks', 'layer' => 'terrain', 'glyph' => '<fg=#a0522d>^</>', 'ground' => 'meadow',
        'art' => ['layer' => 'features', 'tile' => 2960], 'passage' => 'solid', 'atlas' => 'highland'],
      'snowcaps' => ['name' => 'Snowcaps', 'layer' => 'terrain', 'glyph' => '<fg=white>^</>', 'art' => ['awaiting' => true],
        'passage' => 'solid', 'atlas' => 'highland'],
      'lake' => ['name' => 'Lake', 'layer' => 'terrain', 'glyph' => '<fg=blue>~</>', 'art' => ['layer' => 'water', 'tile' => 2048],
        'passage' => 'solid', 'atlas' => 'water'],
      'knolls' => ['name' => 'Knolls', 'layer' => 'terrain', 'glyph' => '<fg=green>n</>', 'art' => ['awaiting' => true],
        'passage' => 'walk', 'encounters' => 'knolls', 'atlas' => 'highland'],
    ],
  ] + $extra;
}

function terrainKindTileset(array $kinds = [], array $extra = []): Tileset
{
  return Tileset::fromArray('synthetic-world', terrainKindTilesetData($kinds, $extra));
}

/** @param list<string> $rows */
function terrainKindLayers(array $rows, array $upper = []): MapLayerSet
{
  $layers = [new MapLayer('terrain', 1, false, 'layers/01.terrain.map.php', implode("\n", $rows))];
  if ($upper !== []) {
    $layers[] = new MapLayer('buildings', 2, false, 'layers/02.buildings.map.php', implode("\n", $upper));
  }
  return new MapLayerSet($layers);
}

/** @param array<string, list<string>> $tileRows Tile identity rows by tile layer name. */
function terrainKindGraphics(Tileset $tileset, array $tileRows): MapGraphics
{
  $order = 0;
  $layers = [];
  foreach ($tileRows as $name => $rows) {
    $layers[] = new MapTileLayer($name, ++$order, "graphics/0{$order}.{$name}.tiles.php", implode("\n", $rows));
  }
  return new MapGraphics($tileset, $layers);
}

describe('kind data', function () {
  it('reads kinds as one-cell pieces that write the glyph, the autotile and a feature\'s ground', function () {
    $tileset = terrainKindTileset();

    $grove = $tileset->kinds['grove'];
    expect($grove->symbol)->toBe('♣')
      ->and($grove->piece->layer)->toBe('terrain')
      ->and($grove->piece->glyphs)->toBe([['♣']])
      ->and($grove->piece->getSourceGrid())->toBe([['<fg=green>♣</>']])
      ->and($grove->piece->tiles)->toBe(['features' => [['2912']], 'ground' => [['2816']]])
      ->and($grove->piece->keeps)->toBe(['substrate'])
      ->and($tileset->kinds['meadow']->piece->tiles)->toBe(['ground' => [['2816']]])
      ->and($tileset->kinds['snowcaps']->awaitingArt)->toBeTrue()
      ->and($tileset->kinds['snowcaps']->piece->tiles)->toBe([])
      ->and(array_keys($tileset->getPlacements(true)))->toBe(['sign', 'meadow', 'path', 'grove', 'peaks', 'snowcaps', 'lake', 'knolls'])
      ->and(array_keys($tileset->getPlacements(false)))->toBe(['sign'])
      ->and(array_keys($tileset->pieces))->toBe(['sign']);
  });

  it('draws nothing graphically for a kind awaiting its art, not even its ground, so its glyph shows', function () {
    $ruin = terrainKindTileset(['ruin' => ['name' => 'Ruin', 'layer' => 'terrain', 'glyph' => '<fg=gray>R</>', 'ground' => 'meadow',
      'art' => ['awaiting' => true], 'passage' => 'solid', 'atlas' => 'obstacle']])->kinds['ruin'];

    expect($ruin->ground)->toBe('meadow')
      ->and($ruin->piece->tiles)->toBe([])
      ->and($ruin->piece->keeps)->toBe([]);
  });

  it('gives each kind the collision its passage and encounters say', function () {
    $kinds = terrainKindTileset()->kinds;

    expect($kinds['meadow']->collision)->toBe(CollisionType::ENCOUNTER)
      ->and($kinds['path']->collision)->toBe(CollisionType::NONE)
      ->and($kinds['peaks']->collision)->toBe(CollisionType::SOLID)
      ->and($kinds['lake']->collision)->toBe(CollisionType::SOLID);
  });

  it('classifies each kind\'s autotile by its passage, so its look agrees with play', function () {
    $tileset = terrainKindTileset();

    expect($tileset->classifyAppearance(2816 + 5))->toBe(TileAppearance::GROUND)
      ->and($tileset->classifyAppearance(2960))->toBe(TileAppearance::BARRIER)
      ->and($tileset->classifyAppearance(2048 + 3))->toBe(TileAppearance::WATER);
  });

  it('refuses kinds a map could not be painted or played with', function (array $kinds, string $message, array $extra = []) {
    expect(fn() => terrainKindTileset($kinds, $extra))->toThrow(InvalidArgumentException::class, $message);
  })->with([
    'a piece id' => [['sign' => ['name' => 'Sign', 'layer' => 'terrain', 'glyph' => '<fg=red>s</>', 'art' => ['awaiting' => true], 'passage' => 'solid',
      'atlas' => 'obstacle']], 'a piece already has this id'],
    'no colour' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => 'b', 'art' => ['awaiting' => true], 'passage' => 'solid',
      'atlas' => 'obstacle']], 'glyph needs its terminal colour'],
    'two glyphs' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => 'ab', 'art' => ['awaiting' => true], 'passage' => 'solid',
      'atlas' => 'obstacle']], 'glyph must be one visible character'],
    'a blank glyph' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red> </>', 'art' => ['awaiting' => true],
      'passage' => 'solid', 'atlas' => 'obstacle']], 'glyph must be one visible character'],
    'vehicle passage' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['awaiting' => true], 'passage' => 'ship',
      'atlas' => 'water']], "passage must be 'walk' or 'solid'"],
    'solid encounters' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['awaiting' => true], 'passage' => 'solid',
      'encounters' => 'cliff', 'atlas' => 'obstacle']], 'is solid, so nobody meets encounters'],
    'an encounter terrain that is no id' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['awaiting' => true],
      'passage' => 'walk', 'encounters' => 'Dark Woods', 'atlas' => 'ground']], 'must be an encounter terrain id'],
    'an unknown atlas role' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['awaiting' => true], 'passage' => 'solid',
      'atlas' => 'lava']], 'atlas must be one of'],
    'a plain tile' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['layer' => 'ground', 'tile' => 7],
      'passage' => 'solid', 'atlas' => 'obstacle']], 'must be an autotile'],
    'a missing sheet' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['layer' => 'ground', 'tile' => 4352],
      'passage' => 'solid', 'atlas' => 'obstacle']], 'which this tileset does not have'],
    'no art state' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'passage' => 'solid', 'atlas' => 'obstacle']],
      "or ['awaiting' => true]"],
    'art awaiting and drawn' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'passage' => 'solid', 'atlas' => 'obstacle',
      'art' => ['awaiting' => true, 'layer' => 'ground', 'tile' => 3008]]], "or ['awaiting' => true]"],
    'an unknown ground' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['awaiting' => true], 'ground' => 'sand',
      'passage' => 'solid', 'atlas' => 'obstacle']], 'which is not one of this tileset'],
    'a feature as ground' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>', 'art' => ['awaiting' => true], 'ground' => 'grove',
      'passage' => 'solid', 'atlas' => 'obstacle']], 'must stand on a ground kind'],
    'a ground on another layer' => [['shore' => ['name' => 'Shore', 'layer' => 'coast', 'glyph' => '<fg=red>s</>', 'art' => ['awaiting' => true],
      'passage' => 'walk', 'encounters' => null, 'atlas' => 'ground'], 'bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>',
      'art' => ['awaiting' => true], 'ground' => 'shore', 'passage' => 'solid', 'atlas' => 'obstacle']], 'share one gameplay layer'],
    'a feature on its ground\'s layer' => [['bad' => ['name' => 'Bad', 'layer' => 'terrain', 'glyph' => '<fg=red>b</>',
      'art' => ['layer' => 'ground', 'tile' => 3008], 'ground' => 'meadow', 'passage' => 'solid', 'atlas' => 'obstacle']],
      'is painted on a layer above its ground'],
    'a glyph shared with other play' => [['ford' => ['name' => 'Ford', 'layer' => 'terrain', 'glyph' => '<fg=cyan>~</>',
      'art' => ['awaiting' => true], 'passage' => 'walk', 'encounters' => null, 'atlas' => 'water']], 'share the glyph ~ on the terrain layer but play differently'],
    'a twin' => [['twin' => ['name' => 'Twin', 'layer' => 'terrain', 'glyph' => '<fg=white>^</>', 'art' => ['awaiting' => true],
      'passage' => 'solid', 'atlas' => 'highland']], 'look the same in both presentations'],
    'a shared autotile' => [['clover' => ['name' => 'Clover', 'layer' => 'terrain', 'glyph' => '<fg=red>c</>', 'art' => ['layer' => 'ground', 'tile' => 2816 + 7],
      'passage' => 'walk', 'encounters' => null, 'atlas' => 'ground']], 'draw the same autotile 2816'],
    'an appearance that disagrees' => [[], 'appearance names tile 2960 as ground', ['appearance' => ['ground' => [2960]]]],
  ]);
});

describe('one passage authority', function () {
  it('feeds the glyph dictionary on maps painted in kinds, overriding flat entries on its layer', function () {
    $kinds = new MapTerrainKinds(terrainKindTileset());
    $layers = terrainKindLayers(['.=♣^~n', 'x.....'], ['  ^   ', '.     ']);
    $dictionary = ['.' => CollisionType::SOLID, '^' => CollisionType::NONE, 'x' => CollisionType::SOLID];

    $grid = MapCollisionResolver::resolveMap($layers, ['tileset' => 'synthetic-world', 'terrainKinds' => true], $dictionary, $kinds)->collisionGrid;

    // The kinds decide their glyphs on terrain; the flat dictionary still decides the buildings layer's '^' and '.'.
    expect($grid[0])->toBe([CollisionType::ENCOUNTER->value, CollisionType::NONE->value, CollisionType::NONE->value,
      CollisionType::SOLID->value, CollisionType::SOLID->value, CollisionType::ENCOUNTER->value])
      ->and($grid[1][0])->toBe(CollisionType::SOLID->value);
  });

  it('never reinterprets a map that has not opted into kinds', function () {
    $layers = terrainKindLayers(['.~^']);
    $dictionary = ['.' => CollisionType::SOLID, '~' => CollisionType::NONE, '^' => CollisionType::NONE];

    expect(MapCollisionResolver::resolveMap($layers, ['tileset' => 'synthetic-world'], $dictionary)->collisionGrid)
      ->toBe([[CollisionType::SOLID->value, CollisionType::NONE->value, CollisionType::NONE->value]])
      ->and(fn() => MapCollisionResolver::resolveMap($layers, ['tileset' => 'synthetic-world'], $dictionary,
        new MapTerrainKinds(terrainKindTileset())))->toThrow(LogicException::class, 'must not apply kinds');
  });

  it('refuses a resolution that leaves out the kinds a map is painted in', function () {
    expect(fn() => MapCollisionResolver::resolveMap(terrainKindLayers(['.']), ['tileset' => 'synthetic-world', 'terrainKinds' => true]))
      ->toThrow(LogicException::class, 'needs the kinds of tileset synthetic-world');
  });

  it('refuses a dictionary section that gives a kind\'s glyph another collision', function () {
    $kinds = new MapTerrainKinds(terrainKindTileset());

    expect($kinds->composeDictionary(['terrain' => ['~' => CollisionType::SOLID]])['terrain']['~'])->toBe(CollisionType::SOLID)
      ->and(fn() => $kinds->composeDictionary(['terrain' => ['~' => CollisionType::NONE]]))
      ->toThrow(InvalidArgumentException::class, 'A kind owns its glyph\'s passage on its layer');
  });

  it('reads glyphs beyond ASCII by their own dictionary entries', function () {
    $layers = terrainKindLayers(['♣▲é']);

    expect(MapCollisionResolver::resolveMap($layers, [], ['♣' => CollisionType::NONE, 'e' => CollisionType::ENCOUNTER])->collisionGrid)
      ->toBe([[CollisionType::NONE->value, CollisionType::SOLID->value, CollisionType::ENCOUNTER->value]]);
  });

  it('reads an opted-in map\'s declaration strictly', function () {
    expect(MapTerrainKinds::isDeclared([]))->toBeFalse()
      ->and(fn() => MapTerrainKinds::isDeclared(['terrainKinds' => 'yes', 'tileset' => 'synthetic-world']))
      ->toThrow(InvalidArgumentException::class, 'is true, or absent')
      ->and(fn() => MapTerrainKinds::isDeclared(['terrainKinds' => true]))
      ->toThrow(InvalidArgumentException::class, 'names the tileset')
      ->and(fn() => new MapTerrainKinds(Tileset::fromArray('plain', ['name' => 'Plain', 'sheets' => ['A2' => 'Graphics/A2.png']])))
      ->toThrow(InvalidArgumentException::class, 'has no terrain kinds');
  });
});

describe('encounters and atlas', function () {
  it('gives each cell its kind\'s encounter terrain through the same glyph walk', function () {
    $kinds = new MapTerrainKinds(terrainKindTileset());
    $layers = terrainKindLayers(['.♣=n', '.♣.~'], ['    ', ' #o ']);
    $dictionary = $kinds->composeDictionary(['o' => CollisionType::PASS_THROUGH, '#' => CollisionType::SOLID]);

    $terrain = $kinds->resolveEncounterTerrain($layers, $dictionary);

    expect([$terrain->getTerrainAt(0, 0), $terrain->getTerrainAt(1, 0), $terrain->getTerrainAt(2, 0), $terrain->getTerrainAt(3, 0)])
      ->toBe(['meadow', 'grove', null, 'knolls'])
      // A pass-through awning keeps the meadow beneath it; a solid object hides the grove.
      ->and($terrain->getTerrainAt(2, 1))->toBe('meadow')
      ->and($terrain->getTerrainAt(1, 1))->toBeNull()
      ->and($terrain->terrains)->toBe(['meadow', 'grove', 'knolls']);
  });

  it('meets terrain-limited troops only on their terrains', function () {
    $kinds = new MapTerrainKinds(terrainKindTileset());
    $layers = terrainKindLayers(['.♣=']);
    $terrain = $kinds->resolveEncounterTerrain($layers, $kinds->composeDictionary([]));
    $manager = new EncounterManager(new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor());

    $manager->configure(['troops' => ['Wolves' => 2, 'Sprites' => ['weight' => 3, 'terrains' => ['grove']],
      'Ghosts' => ['weight' => 1, 'terrains' => ['swamp']]], 'rate' => 10], null, $terrain);

    expect($manager->getTroopWeightsAt(new Vector2(0, 0)))->toBe(['Wolves' => 2])
      ->and($manager->getTroopWeightsAt(new Vector2(1, 0)))->toBe(['Wolves' => 2, 'Sprites' => 3])
      ->and($manager->getTroopWeightsAt(new Vector2(2, 0)))->toBe(['Wolves' => 2]);
  });

  it('shows each kind as its atlas role', function () {
    $kinds = new MapTerrainKinds(terrainKindTileset());
    $layers = terrainKindLayers(['.=♣^~']);
    $map = ['tileset' => 'synthetic-world', 'terrainKinds' => true];

    $atlas = MapAtlasTerrain::readMap($map, $layers, $kinds);

    expect(array_map(fn(int $x): ?string => $atlas->getRole($x, 0), range(0, 4)))->toBe(['ground', 'road', 'vegetation', 'highland', 'water'])
      ->and(fn() => MapAtlasTerrain::readMap($map + ['atlas' => ['terrain' => ['terrain' => ['symbols' => ['~' => 'ground']]]]], $layers, $kinds))
      ->toThrow(InvalidArgumentException::class, "A kind owns its glyph's atlas role");
  });
});

describe('agreement of glyph and tiles', function () {
  it('accepts cells painted with one kind in both presentations, and lists kinds awaiting art', function () {
    $tileset = terrainKindTileset();
    $layers = terrainKindLayers(['.♣^n', '~=..']);
    $graphics = terrainKindGraphics($tileset, [
      'substrate' => ['1536 1536 1536 1536', '1536 1536 1536 1536'],
      'ground' => ['2817 2816 2816 0', '0 2864 2830 2816'],
      'features' => ['0 2912 2960 0', '0 0 0 0'],
      'water' => ['0 0 0 0', '2050 0 0 0'],
    ]);

    $issues = MapTerrainKindRule::inspect('world', $layers, $graphics, new MapTerrainKinds($tileset));

    expect(array_map(fn($issue): array => [$issue->rule, $issue->cells], $issues))
      ->toBe([[MapTerrainKindRule::AWAITING_ART, [[3, 0]]]]);
  });

  it('reports a glyph and tiles that show different kinds, either way round', function () {
    $tileset = terrainKindTileset();
    // A meadow glyph over path tiles, a grove glyph without its ground, and meadow tiles under an unbound glyph.
    $layers = terrainKindLayers(['.♣x', 'i..']);
    $graphics = terrainKindGraphics($tileset, [
      'ground' => ['2864 0 2816', '0 2816 2816'],
      'features' => ['0 2912 0', '4 0 0'],
    ]);

    $issues = MapTerrainKindRule::inspect('world', $layers, $graphics, new MapTerrainKinds($tileset));

    expect(array_map(fn($issue): array => [$issue->rule, $issue->cells], $issues))->toBe([
      [MapTerrainKindRule::DISAGREEMENT, [[0, 0], [2, 0]]],
      [MapTerrainKindRule::DISAGREEMENT, [[1, 0]]],
    ]);
  });

  it('leaves the glyphs of kinds awaiting art to their own warning, not to missing graphics', function () {
    $tileset = terrainKindTileset();
    $layers = terrainKindLayers(['.n']);
    $graphics = terrainKindGraphics($tileset, ['ground' => ['2816 0']]);
    $rules = fn(array $data): array => array_map(fn($issue): array => [$issue->rule, $issue->cells],
      MapCompletenessValidator::validateCoverage('world', $data, $layers, $graphics, '/nonexistent'));

    // No sheet can be drawn here, so every glyph shows; only the meadow's is a missing presentation once kinds are painted.
    expect($rules(['tileset' => 'synthetic-world']))->toBe([['graphics.glyph', [[0, 0]]], ['graphics.glyph', [[1, 0]]]])
      ->and($rules(['tileset' => 'synthetic-world', 'terrainKinds' => true]))->toBe([['graphics.glyph', [[0, 0]]]]);
  });

  it('checks only maps that opt in, and reports one whose tileset declares no kinds', function () {
    $plain = Tileset::fromArray('plain', ['name' => 'Plain', 'sheets' => ['A2' => 'Graphics/A2.png']]);
    $layers = terrainKindLayers(['.']);
    $graphics = terrainKindGraphics($plain, ['ground' => ['2864']]);

    expect(MapCompletenessValidator::validateTerrainKinds('world', ['tileset' => 'plain'], $layers, $graphics))->toBe([])
      ->and(array_column(MapCompletenessValidator::validateTerrainKinds('world', ['tileset' => 'plain', 'terrainKinds' => true],
        $layers, $graphics), 'rule'))->toBe(['terrain.kinds']);
  });
});

it('owns the tile layers kinds paint for the layer they are painted on, on a map painted in them', function () {
  $owners = fn(bool $painted): array => MapGraphics::resolveLayerOwners(null, ['substrate', 'ground', 'features', 'water'], ['terrain'],
    terrainKindTileset(), 'world', $painted);

  expect($owners(true))->toBe(['substrate' => null, 'ground' => 'terrain', 'features' => 'terrain', 'water' => 'terrain'])
    // Without kinds only the sign piece writes, and only its features layer.
    ->and($owners(false))->toBe(['substrate' => null, 'ground' => null, 'features' => 'terrain', 'water' => null]);
});
