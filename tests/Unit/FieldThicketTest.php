<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapThickets;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteThicket;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderHostInterface;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\SpriteThicketHostInterface;
use Ichiloto\Engine\Rendering\Sprites\ThicketSubjectInterface;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\AbstractScene;

/**
 * Thickets: foliage that hides the lower part of a character standing in it
 * in the graphical field, authored per foliage type with its depth and named
 * by terrain kinds and tiles. A synthetic tileset only: a grove kind on a
 * features layer, a reed tile, a rustle-free fern tile and a corner-edged
 * pond kind. Nothing here reads production content.
 */

/** @return array<string, mixed> */
function thicketTilesetData(array $thickets = [], array $kinds = [], array $extra = []): array
{
  return [
    'name' => 'Synthetic thickets',
    'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png', 'B' => 'Graphics/B.png'],
    'above' => [9],
    'thickets' => $thickets + [
      'grove' => ['name' => 'Grove', 'depth' => 20, 'opacity' => 0],
      'reeds' => ['name' => 'Reeds', 'depth' => 10, 'opacity' => 0.5, 'tiles' => [3, 2960]],
      'ferns' => ['name' => 'Ferns', 'depth' => 20, 'opacity' => 0.25, 'rustle' => false, 'tiles' => [4]],
      'canopy' => ['name' => 'Canopy', 'depth' => 8, 'opacity' => 0, 'tiles' => [9]],
      'lilies' => ['name' => 'Lilies', 'depth' => 6, 'opacity' => 0.5],
    ],
    'kinds' => $kinds + [
      'meadow' => ['name' => 'Meadow', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>', 'art' => ['layer' => 'ground', 'tile' => 2816],
        'passage' => 'walk', 'atlas' => 'ground'],
      'grove' => ['name' => 'Grove', 'layer' => 'terrain', 'glyph' => '<fg=green>♣</>', 'ground' => 'meadow',
        'art' => ['layer' => 'features', 'tile' => 2912], 'passage' => 'walk', 'atlas' => 'vegetation', 'thicket' => 'grove'],
      'pond' => ['name' => 'Pond', 'layer' => 'terrain', 'glyph' => '<fg=blue>~</>',
        'art' => ['layer' => 'water', 'tile' => 2048, 'corners' => 'Graphics/Pond.corners.png'], 'passage' => 'walk', 'atlas' => 'water',
        'thicket' => 'lilies'],
    ],
  ] + $extra;
}

function thicketTileset(array $thickets = [], array $kinds = [], array $extra = []): Tileset
{
  return Tileset::fromArray('synthetic-thickets', thicketTilesetData($thickets, $kinds, $extra));
}

/** @param array<string, list<string>> $tileRows Tile identity rows by tile layer name, in drawing order. */
function thicketGraphics(array $tileRows, ?Tileset $tileset = null): MapGraphics
{
  $order = 0;
  $layers = [];
  foreach ($tileRows as $name => $rows) {
    $layers[] = new MapTileLayer($name, ++$order, "graphics/0{$order}.{$name}.tiles.php", implode("\n", $rows));
  }
  return new MapGraphics($tileset ?? thicketTileset(), $layers);
}

describe('authored thickets', function () {
  it('reads each foliage type with its depth and opacity, and the thicket each tile is, in any of its shapes', function () {
    $tileset = thicketTileset();

    expect(array_keys($tileset->thickets))->toBe(['grove', 'reeds', 'ferns', 'canopy', 'lilies'])
      ->and($tileset->thickets['grove'])->toMatchArray(['depth' => 20, 'opacity' => 0.0, 'rustle' => true, 'tiles' => []])
      ->and($tileset->thickets['ferns']->rustle)->toBeFalse()
      // A kind's own autotile, in every shape, is the thicket its kind names.
      ->and($tileset->kinds['grove']->thicket)->toBe('grove')
      ->and($tileset->getThicket(2912)?->id)->toBe('grove')
      ->and($tileset->getThicket(2912 + 46)?->id)->toBe('grove')
      // Any other tile is the thicket that lists it.
      ->and($tileset->getThicket(3)?->id)->toBe('reeds')
      ->and($tileset->getThicket(2960 + 12)?->id)->toBe('reeds')
      // A kind without a thicket, an unlisted tile and the empty tile hide nobody.
      ->and($tileset->getThicket(2816))->toBeNull()
      ->and($tileset->getThicket(5))->toBeNull()
      ->and($tileset->getThicket(0))->toBeNull();
  });

  it('keeps every tileset that names no thickets as it was', function () {
    $data = thicketTilesetData();
    unset($data['thickets'], $data['kinds']['grove']['thicket'], $data['kinds']['pond']['thicket']);
    $tileset = Tileset::fromArray('plain', $data);

    expect($tileset->thickets)->toBe([])
      ->and($tileset->getThicket(2912))->toBeNull();
  });

  it('refuses a thicket the field could not draw, and a tile with two', function (array $thickets, array $kinds, string $message) {
    expect(fn() => thicketTileset($thickets, $kinds))->toThrow(InvalidArgumentException::class, $message);
  })->with([
    'no depth' => [['bad' => ['name' => 'Bad', 'opacity' => 0]], [], 'needs its depth'],
    'nothing hidden' => [['bad' => ['name' => 'Bad', 'depth' => 0, 'opacity' => 0]], [], 'depth must be 1 to 48'],
    'deeper than a cell' => [['bad' => ['name' => 'Bad', 'depth' => 49, 'opacity' => 0]], [], 'depth must be 1 to 48'],
    'fully opaque' => [['bad' => ['name' => 'Bad', 'depth' => 8, 'opacity' => 1]], [], 'opacity must be from 0'],
    'no opacity' => [['bad' => ['name' => 'Bad', 'depth' => 8]], [], 'needs the opacity'],
    'a rustle that is no flag' => [['bad' => ['name' => 'Bad', 'depth' => 8, 'opacity' => 0, 'rustle' => 'yes']], [], 'rustle is true or false'],
    'no name' => [['bad' => ['depth' => 8, 'opacity' => 0]], [], 'needs a name'],
    'an unknown field' => [['bad' => ['name' => 'Bad', 'depth' => 8, 'opacity' => 0, 'colour' => 'green']], [], 'must be an array of'],
    'a tile two thickets list' => [['bad' => ['name' => 'Bad', 'depth' => 8, 'opacity' => 0, 'tiles' => [3]]], [], 'both list tile 3'],
    'a kind\'s own autotile listed' => [['bad' => ['name' => 'Bad', 'depth' => 8, 'opacity' => 0, 'tiles' => [2912 + 4]]], [],
      "terrain kind grove's own autotile; name the thicket on the kind"],
    'a kind naming no thicket of the tileset' => [[], ['bush' => ['name' => 'Bush', 'layer' => 'terrain', 'glyph' => '<fg=green>b</>',
      'art' => ['layer' => 'features', 'tile' => 3008], 'passage' => 'walk', 'atlas' => 'vegetation', 'thicket' => 'hedge']],
      "thicket must name one of this tileset's thickets"],
  ]);
});

describe('map thickets', function () {
  it('hides a character by its cell\'s kind and tiles, and nobody where no thicket\'s foliage is drawn', function () {
    $thickets = new MapThickets(thicketGraphics([
      'ground' => ['2816 2816 2816 2816'],
      'features' => ['2912 0 0 0'],
      'objects' => ['0 3 0 9'],
    ]));

    expect($thickets->findSpriteThicketAt(0, 0, false)?->toArray())->toBe(['depth' => 20, 'opacity' => 0.0,
      'rustle' => ['layer' => 'tiles:features']])
      ->and($thickets->findSpriteThicketAt(1, 0, false)?->toArray())->toBe(['depth' => 10, 'opacity' => 0.5,
        'rustle' => ['layer' => 'tiles:objects']])
      // A thicket tile drawn above characters rustles on the band that draws it.
      ->and($thickets->findSpriteThicketAt(3, 0, false)?->rustleLayer)->toBe('tiles:objects:above')
      ->and($thickets->findSpriteThicketAt(2, 0, false))->toBeNull()
      // Outside the map nothing hides a character.
      ->and($thickets->findSpriteThicketAt(9, 9, false))->toBeNull();
  });

  it('hides a character in the deepest of its cell\'s thickets, the uppermost layer\'s when equally deep', function () {
    $thickets = new MapThickets(thicketGraphics([
      'features' => ['2912 2912 3'],
      'objects' => ['3 4 0'],
    ]));

    expect($thickets->findSpriteThicketAt(0, 0, false)?->depth)->toBe(20)
      ->and($thickets->findSpriteThicketAt(0, 0, false)?->rustleLayer)->toBe('tiles:features')
      // The grove and the ferns are both 20 deep; the ferns lie on the upper layer and never rustle.
      ->and($thickets->findSpriteThicketAt(1, 0, false)?->toArray())->toBe(['depth' => 20, 'opacity' => 0.25])
      ->and($thickets->findSpriteThicketAt(2, 0, false)?->depth)->toBe(10);
  });

  it('keeps the hiding and holds the foliage still under reduced motion, and for a corner-edged kind', function () {
    $thickets = new MapThickets(thicketGraphics([
      'water' => ['2048 0'],
      'features' => ['0 2912'],
    ]));

    expect($thickets->findSpriteThicketAt(1, 0, true)?->toArray())->toBe(['depth' => 20, 'opacity' => 0.0])
      // A corner-edged kind's art lies on the corners between cells, so no one tile of the cell rustles.
      ->and($thickets->findSpriteThicketAt(0, 0, false)?->toArray())->toBe(['depth' => 6, 'opacity' => 0.5]);
  });
});

describe('sprite thickets', function () {
  it('sends a thicket only to a renderer that negotiated sprite_thicket, and keeps it with the sprite', function () {
    $thicket = new PresentationSpriteThicket(20, 0.0, 'tiles:features');
    $sprite = new PresentationSprite('player', 'hero.png', 3, 4, 48, 48, PresentationSpriteAnchor::BOTTOM_CENTER, 100,
      motion: new PresentationSpriteMotion(16 / 60), thicket: $thicket);
    $plain = new PresentationSprite('player', 'hero.png', 3, 4, 48, 48, PresentationSpriteAnchor::BOTTOM_CENTER, 100,
      motion: new PresentationSpriteMotion(16 / 60));

    expect($sprite->toArray())->toBe($plain->toArray())
      ->and($sprite->toArray(thicket: true)['thicket'])->toBe(['depth' => 20, 'opacity' => 0.0, 'rustle' => ['layer' => 'tiles:features']])
      ->and($plain->toArray(thicket: true))->toBe($plain->toArray())
      ->and($sprite->withoutMotion()->thicket)->toBe($thicket)
      ->and(new RendererSessionConfig('Thickets', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2)->getNegotiableCapabilities())
      ->toContain(RendererSessionConfig::SPRITE_THICKET)
      ->and(new RendererSessionConfig('Thickets', sys_get_temp_dir())->getNegotiableCapabilities())
      ->not->toContain(RendererSessionConfig::SPRITE_THICKET)
      ->and(fn() => new RendererSessionConfig('Thickets', sys_get_temp_dir(), requiredCapabilities: [RendererSessionConfig::SPRITE_THICKET]))
      ->toThrow(InvalidArgumentException::class);
  });

  it('hides at most the sprite it hides, and refuses a hiding the renderer could not draw', function () {
    expect(new PresentationSpriteThicket(40, 0.5)->fitHeight(24)->depth)->toBe(24)
      ->and(new PresentationSpriteThicket(20, 0.5)->fitHeight(48)->depth)->toBe(20)
      ->and(fn() => new PresentationSprite('a', 'a.png', 0, 0, 48, 24, thicket: new PresentationSpriteThicket(40, 0.0)))
      ->toThrow(InvalidArgumentException::class, 'whole height')
      ->and(fn() => new PresentationSpriteThicket(0, 0.0))->toThrow(InvalidArgumentException::class)
      ->and(fn() => new PresentationSpriteThicket(8, 1.0))->toThrow(InvalidArgumentException::class)
      ->and(fn() => new PresentationSpriteThicket(8, 0.0, ''))->toThrow(InvalidArgumentException::class);
  });
});

/** A field character for the collector: a player, an NPC or a follower alike. */
final class ThicketTestCharacter implements ThicketSubjectInterface
{
  public bool $standsInFoliage { get => !$this->isObject; }

  public function __construct(private string $id, public Vector2 $cell, private ?PresentationSpriteMotion $motion = null,
    private bool $isObject = false, private int $height = 48) {}

  public function getGraphicalSpriteId(): string { return $this->id; }
  public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition { return new GraphicalSpriteDefinition('hero.png', 48, $this->height, lift: 6); }
  public function getGraphicalSpriteWorldPosition(): Vector2 { return clone $this->cell; }
  public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion { return $this->motion; }
}

/** A field scene with a thicket map: what the game scene does, without a game around it. */
final class ThicketTestScene extends AbstractScene implements GraphicalSpriteProviderHostInterface, SpriteThicketHostInterface
{
  /** @param list<GraphicalSpriteProviderInterface> $providers */
  public function __construct(public MapThickets $thickets, public array $providers, public bool $reducedMotion = false)
  {
    $this->camera = new Camera($this, 8, 4, worldSpace: array_fill(0, 4, str_repeat('.', 8)));
  }

  public function getGraphicalSpriteProviders(): iterable { return $this->providers; }

  public function findSpriteThicketAt(Vector2 $cell): ?PresentationSpriteThicket
  {
    return $this->thickets->findSpriteThicketAt((int)$cell->x, (int)$cell->y, $this->reducedMotion);
  }
}

describe('characters in thickets', function () {
  beforeEach(function () {
    $this->thickets = new MapThickets(thicketGraphics([
      'ground' => ['2816 2816 2816 2816'],
      'features' => ['0 2912 2912 0'],
    ]));
    $this->collect = function (array $providers, bool $reducedMotion = false): array {
      $sprites = new GraphicalSpriteCollector()->collect(new ThicketTestScene($this->thickets, $providers, $reducedMotion));
      return array_combine(array_map(static fn(PresentationSprite $sprite): string => $sprite->id, $sprites), $sprites);
    };
  });

  it('hides a character walking into a thicket from the step that takes it there, with that step\'s slide', function () {
    // The step from open meadow into the grove is committed: the sprite already names the grove it slides into.
    $entering = ($this->collect)([new ThicketTestCharacter('player', new Vector2(1, 0), new PresentationSpriteMotion(16 / 60))])['player'];
    // Walking on through the grove keeps it hidden.
    $through = ($this->collect)([new ThicketTestCharacter('player', new Vector2(2, 0), new PresentationSpriteMotion(16 / 60))])['player'];
    // The step out names no thicket, so the renderer raises the character out of it along that slide.
    $leaving = ($this->collect)([new ThicketTestCharacter('player', new Vector2(3, 0), new PresentationSpriteMotion(16 / 60))])['player'];

    expect($entering->thicket?->toArray())->toBe(['depth' => 20, 'opacity' => 0.0, 'rustle' => ['layer' => 'tiles:features']])
      ->and($entering->motion?->seconds)->toBe(16 / 60)
      ->and($through->thicket?->depth)->toBe(20)
      ->and($leaving->thicket)->toBeNull()
      ->and($leaving->motion?->seconds)->toBe(16 / 60);
  });

  it('hides NPCs and followers as it hides the player, but never an object character or a sprite that is no character', function () {
    $object = $this->createStub(GraphicalSpriteProviderInterface::class);
    $object->method('getGraphicalSpriteId')->willReturn('chest-object');
    $object->method('getGraphicalSpriteWorldPosition')->willReturn(new Vector2(2, 0));
    $object->method('getGraphicalSpriteDefinition')->willReturn(new GraphicalSpriteDefinition('chest.png', 48, 48));
    $sprites = ($this->collect)([
      new ThicketTestCharacter('player', new Vector2(1, 0)),
      new ThicketTestCharacter('npc:guide', new Vector2(2, 0)),
      new ThicketTestCharacter('follower:1', new Vector2(1, 0)),
      new ThicketTestCharacter('npc:chest', new Vector2(2, 0), isObject: true),
      new ThicketTestCharacter('npc:far', new Vector2(0, 0)),
      $object,
    ]);

    expect(array_map(static fn(PresentationSprite $sprite): ?int => $sprite->thicket?->depth, $sprites))->toBe([
      'player' => 20, 'npc:guide' => 20, 'follower:1' => 20, 'npc:chest' => null, 'npc:far' => null, 'chest-object' => null]);
  });

  it('keeps the hiding without the rustle under reduced motion, and fits a short sprite', function () {
    $sprites = ($this->collect)([new ThicketTestCharacter('player', new Vector2(1, 0)),
      new ThicketTestCharacter('imp', new Vector2(2, 0), height: 12)], true);

    expect($sprites['player']->thicket?->toArray())->toBe(['depth' => 20, 'opacity' => 0.0])
      ->and($sprites['imp']->thicket?->depth)->toBe(12);
  });
});
