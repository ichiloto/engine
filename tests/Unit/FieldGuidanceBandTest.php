<?php

use Ichiloto\Engine\Animations\Field\FieldEdgeSprite;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldEffectSprite;
use Ichiloto\Engine\Animations\Field\FieldScreenSprite;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\Waymarks\{FieldWaymark, FieldWaymarkAnchor, FieldWaymarkRole, FieldWaymarkTerminalPresenter};
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteBob;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** A synthetic project: replaceable cue art with a plate and pointers, a cued effect, and a tileset with one above tile. */
function writeGuidanceBandProject(string $root, array $style = []): void
{
  writeTestPng($root . '/Graphics/mark.png', 16, 2);
  writeTestPng($root . '/Graphics/pointer.png', 3, 3);
  writeTestPng($root . '/Graphics/plate.png', 4, 4);
  writeTestPng($root . '/Graphics/glow.png', 4, 4);
  foreach (['mark' => 'Graphics/mark.png', 'glow' => 'Graphics/glow.png'] as $id => $asset) {
    @mkdir("{$root}/Animations/{$id}", 0777, true);
    file_put_contents("{$root}/Animations/{$id}/{$id}.timeline.php", '<?php return ' . var_export(['fps' => 5, 'lengthFrames' => 2,
      'playback' => 'loop', 'restFrame' => 0, 'tracks' => [['id' => $id, 'type' => 'image', 'asset' => $asset,
        'sheet' => ['columns' => $id === 'mark' ? 8 : 1, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1], 'depth' => 'front',
        'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 0]]]]], true) . ';');
  }
  $pointer = array_fill_keys(['east', 'southeast', 'south', 'southwest', 'west', 'northwest', 'north', 'northeast'],
    ['asset' => 'Graphics/pointer.png', 'quarterTurns' => 0]);
  @mkdir($root . '/Data/Presentation', 0777, true);
  file_put_contents($root . '/Data/Presentation/field.php', '<?php return ' . var_export([
    'actionPrompt' => ['effect' => 'mark'],
    'cues' => ['bright-yellow' => ['effect' => 'mark', 'pointer' => $pointer, 'plate' => 'Graphics/plate.png']],
    'cueStyle' => $style], true) . ';');
  writeTestPng($root . '/Graphics/Tilesets/A2.png', 768, 576);
  writeTestPng($root . '/Graphics/Tilesets/B.png', 768, 768);
  @mkdir($root . '/Data/Tilesets', 0777, true);
  file_put_contents($root . '/Data/Tilesets/home.php', "<?php\nreturn ['name' => 'Home', 'sheets' => ['A2' => 'Graphics/Tilesets/A2.png',"
    . " 'B' => 'Graphics/Tilesets/B.png'], 'above' => [5]];\n");
}

/** A map whose roof, an above-character tile on the highest layer any map may author, covers cell (1, 0). */
function createGuidanceBandWorld(string $root): PresentationWorld
{
  $map = $root . '/Maps/home';
  @mkdir($map . '/graphics', 0777, true);
  file_put_contents($map . '/graphics/01.floor.tiles.php', MapGridSource::buildSource("2816 2816 2816 2816\n2816 2816 2816 2816", 'TILES'));
  file_put_contents($map . '/graphics/' . sprintf('%02d', MapLayer::MAX_ORDER) . '.roof.tiles.php',
    MapGridSource::buildSource("0 5 0 0\n0 0 0 0", 'TILES'));
  $layers = new MapLayerSet([new MapLayer('terrain', MapLayer::MAX_ORDER, false, 'terrain', "....\n....")]);
  return PresentationWorld::getFromLayers($layers, 'map', MapGraphics::loadFromDirectory($map, 'home', 'home', $layers, $root), $root);
}

/** @return list<int> Every layer the world draws. */
function getGuidanceBandWorldLayers(PresentationWorld $world): array
{
  return array_column($world->getOperations(true, true)[0]['value']['layers'], 'layer');
}

function showGuidanceBandWaymark(FieldEffectManager $manager, Vector2 $cell, ?string $objectId = null): void
{
  $manager->showWaymarks([new FieldWaymark('w', FieldWaymarkRole::TARGET, new FieldWaymarkAnchor($cell, $objectId), 'Go')]);
}

beforeEach(function () {
  $this->root = createTestDirectory('ichiloto-guidance-band-');
  $this->debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
  $this->view = new FieldViewport(new RendererGridConfig(135, 36, 10, 20));
});

afterEach(function () {
  foreach ($this->debug as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
});

it('keeps every map band of any authored order inside the field world, below guidance, and guidance below UI', function () {
  $highest = max(array_map(static fn(int $order): int => max(PresentationLayerPolicy::getTileLayerOrder($order, false),
    PresentationLayerPolicy::getTileLayerOrder($order, true)), range(MapLayer::MIN_ORDER, MapLayer::MAX_ORDER)));

  expect($highest)->toBeLessThan(PresentationLayerPolicy::FIELD_GUIDANCE)
    ->and(PresentationLayerPolicy::isFieldWorldLayer($highest))->toBeTrue()
    ->and(PresentationLayerPolicy::FIELD_EFFECT_FRONT)->toBeLessThan(PresentationLayerPolicy::MAP_ABOVE)
    ->and(PresentationLayerPolicy::FIELD_GUIDANCE)->toBeLessThan(PresentationLayerPolicy::FIELD_GUIDANCE_FRONT)
    ->and(PresentationLayerPolicy::FIELD_GUIDANCE_FRONT)->toBeLessThan(PresentationLayerPolicy::UI)
    ->and(PresentationLayerPolicy::isFieldWorldLayer(PresentationLayerPolicy::FIELD_GUIDANCE))->toBeFalse()
    ->and(fn() => PresentationLayerPolicy::getTileLayerOrder(MapLayer::MAX_ORDER + 1, true))->toThrow(InvalidArgumentException::class)
    ->and(fn() => PresentationLayerPolicy::getTileLayerOrder(-1, false))->toThrow(InvalidArgumentException::class);
});

it('keeps characters and world objects out of the guidance band', function () {
  expect(fn() => FieldSpriteRole::readDefinition(['asset' => 'Graphics/mark.png', 'layer' => PresentationLayerPolicy::FIELD_GUIDANCE]))
    ->toThrow(InvalidArgumentException::class)
    ->and(FieldSpriteRole::readDefinition(['asset' => 'Graphics/mark.png', 'layer' => PresentationLayerPolicy::FIELD_GUIDANCE - 1])->layer)
    ->toBe(PresentationLayerPolicy::FIELD_GUIDANCE - 1);
});

it('draws a waymark over an above-character tile on the highest map layer, on screen and at the view edge, and under UI', function () {
  writeGuidanceBandProject($this->root);
  $world = createGuidanceBandWorld($this->root);
  $roof = max(getGuidanceBandWorldLayers($world));
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->installMap('map', [], null, []);

  foreach ([new Vector2(1, 0), new Vector2(-100, 0)] as $cell) {
    showGuidanceBandWaymark($manager, $cell);
    $sprites = $manager->getSprites([], $this->view, ['x' => 0, 'y' => 0], false);
    $projected = array_map(static fn(FieldEffectSprite $sprite) => new GraphicalSpriteProjector()
      ->project($sprite, new Camera(makeCameraTestScene(), 135, 36)), $sprites);
    PresentationLayerPolicy::assertWorldSprites($projected);
    expect($roof)->toBe(PresentationLayerPolicy::MAP_ABOVE + MapLayer::MAX_ORDER)
      ->and($sprites)->not->toBe([])
      ->and(array_map(static fn(FieldEffectSprite $sprite): int => $sprite->getGraphicalSpriteDefinition()->layer, $sprites))
      ->each->toBeGreaterThan($roof)->toBeLessThan(PresentationLayerPolicy::UI);
  }
  // At the edge, the plate stays under its mark and the pointer beside it.
  $layers = [];
  foreach ($sprites as $sprite) {
    $layers[$sprite instanceof FieldEdgeSprite ? 'pointer'
      : (str_ends_with($sprite->getGraphicalSpriteId(), ':edge-plate') ? 'plate' : 'mark')] = $sprite->getGraphicalSpriteDefinition()->layer;
  }
  expect($layers)->toBe(['plate' => PresentationLayerPolicy::FIELD_GUIDANCE, 'mark' => PresentationLayerPolicy::FIELD_GUIDANCE_FRONT,
    'pointer' => PresentationLayerPolicy::FIELD_GUIDANCE_FRONT]);
});

it('draws the action prompt and event cues as guidance and leaves other field effects with the characters', function () {
  writeGuidanceBandProject($this->root);
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $cue = new ScriptEventTrigger(new Rect(3, 1, 1, 1), ['mode' => 'action', 'reusable' => false], mapId: 'map', marker: 'E',
    cue: ['symbol' => '!', 'color' => 'bright-yellow']);
  $manager->installMap('map', [['id' => 'glow', 'effect' => 'glow', 'anchor' => ['cell' => ['x' => 2, 'y' => 1]]]], null, [$cue]);
  $player = new FieldEffectSprite('player', new GraphicalSpriteDefinition('Graphics/mark.png', 48, 48), new Vector2(0, 1));
  $manager->showActionPrompt('player');
  $layers = [];
  foreach ($manager->getSprites([$player], $this->view, ['x' => 0, 'y' => 0], false) as $sprite) {
    $layers[$sprite->getGraphicalSpriteId()] = $sprite->getGraphicalSpriteDefinition()->layer;
  }

  expect($layers)->toBe([
    'field-effect:map:map-glow:glow' => PresentationLayerPolicy::FIELD_EFFECT_FRONT,
    'field-effect:map:cue-E:plate' => PresentationLayerPolicy::FIELD_GUIDANCE,
    'field-effect:map:cue-E:mark' => PresentationLayerPolicy::FIELD_GUIDANCE_FRONT,
    'field-effect:map:' . FieldEffectManager::ACTION_PROMPT_ID . ':mark' => PresentationLayerPolicy::FIELD_GUIDANCE_FRONT,
  ]);
});

it('declares the bob once to a renderer that draws it, and sends a whole-pixel rise each frame to one that cannot', function () {
  writeGuidanceBandProject($this->root, ['bob' => ['height' => 4, 'seconds' => 1.0]]);
  $sample = static function (FieldEffectManager $manager, float $seconds, bool $reduced) {
    $manager->update($seconds, $reduced, []);
    $definitions = [];
    foreach ($manager->getSprites([], new FieldViewport(new RendererGridConfig(135, 36, 10, 20)), ['x' => 0, 'y' => 0], $reduced) as $sprite) {
      $definitions[] = $sprite->getGraphicalSpriteDefinition();
    }
    return $definitions;
  };
  foreach ([new Vector2(3, 2), new Vector2(-100, 2)] as $cell) {
    $declared = new FieldEffectManager($this->root);
    $declared->setCapabilities(true, true, bob: true);
    $declared->installMap('map', [], null, []);
    showGuidanceBandWaymark($declared, $cell);
    $first = $sample($declared, 0.0, false);
    $later = $sample($declared, 0.5, false);
    // Unchanged between frames, so nothing is resent while the renderer draws the rise and fall.
    expect($later)->toEqual($first)
      ->and(array_map(static fn(GraphicalSpriteDefinition $definition) => $definition->bob, $first))
      ->each->toEqual(new PresentationSpriteBob(4, 1.0))
      ->and(array_map(static fn(GraphicalSpriteDefinition $definition) => $definition->bob, $sample($declared, 0.25, true)))
      ->each->toBeNull();

    $legacy = new FieldEffectManager($this->root);
    $legacy->setCapabilities(true, true);
    $legacy->installMap('map', [], null, []);
    showGuidanceBandWaymark($legacy, $cell);
    $rest = array_map(static fn(GraphicalSpriteDefinition $definition) => $definition->lift, $sample($legacy, 0.0, false));
    $top = array_map(static fn(GraphicalSpriteDefinition $definition) => $definition->lift, $sample($legacy, 0.5, false));
    expect(array_map(static fn(GraphicalSpriteDefinition $definition) => $definition->bob, $sample($legacy, 0.0, false)))->each->toBeNull()
      ->and(array_map(static fn(int $high, int $low): int => $high - $low, $top, $rest))->each->toBe(4);
  }
});

it('rises and falls on one shared curve, the same the renderer draws', function () {
  $bob = new PresentationSpriteBob(4, 1.0);

  expect(array_map(static fn(float $t): float => round($bob->getOffset($t), 6), [0.0, 0.25, 0.5, 0.75, 1.0, 1.5]))
    ->toBe([0.0, 2.0, 4.0, 2.0, 0.0, 4.0])
    ->and($bob->toArray())->toBe(['height' => 4, 'seconds' => 1.0])
    ->and(fn() => new PresentationSpriteBob(0, 1.0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new PresentationSpriteBob(4, 0.0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new GraphicalSpriteDefinition('Graphics/mark.png', 48, 48, lift: 46, bob: $bob))->toThrow(InvalidArgumentException::class);
});

it('slides edge markers with the camera for a renderer that slides pinned sprites, and pins them still otherwise', function () {
  writeGuidanceBandProject($this->root);
  $step = new PresentationSpriteMotion(16 / 60);
  foreach ([true, false] as $pinnedMotion) {
    $manager = new FieldEffectManager($this->root);
    $manager->setCapabilities(true, true, pinnedMotion: $pinnedMotion);
    $manager->installMap('map', [], null, []);
    showGuidanceBandWaymark($manager, new Vector2(-100, 2));
    $edge = $manager->getSprites([], $this->view, ['x' => 0, 'y' => 0], false, cameraMotion: $step);
    expect($edge)->each->toBeInstanceOf(FieldScreenSprite::class)
      ->and(array_map(static fn(FieldEffectSprite $sprite) => $sprite->getGraphicalSpriteMotion(), $edge))
      ->each->toBe($pinnedMotion ? $step : null);
  }
  // On screen, a mark stays in the field, which the camera already carries.
  showGuidanceBandWaymark($manager, new Vector2(3, 2));
  expect(array_map(static fn(FieldEffectSprite $sprite) => $sprite instanceof FieldScreenSprite,
    $manager->getSprites([], $this->view, ['x' => 0, 'y' => 0], false, cameraMotion: $step)))->each->toBeFalse();
});

it('draws Terminal waymarks over every field glyph and under UI', function () {
  $prior = new ReflectionClass(Console::class)->getStaticProperties();
  try {
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalOutputEnabled' => false, 'output' => null, 'overlays' => [],
      'buffer' => [], 'layerCells' => [], 'layerPriorities' => [], 'presentationBaseCells' => [], 'retainedPresentation' => null,
      'trackLayers' => false, 'activeLayer' => null, 'activeLayerPriority' => 0] as $name => $value) {
      new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
    Console::syncDimensions(20, 8);
    Console::setLayerTracking(true);
    $camera = new Camera(makeCameraTestScene(), 20, 8);
    Console::withLayer('npc', fn() => Console::write('N', 3, 2), PresentationLayerPolicy::WORLD);
    Console::withLayer('effect', fn() => Console::write('E', 3, 2), PresentationLayerPolicy::FIELD_EFFECT_FRONT);
    Console::withLayer('roof', fn() => Console::write('R', 3, 2), PresentationLayerPolicy::MAP_ABOVE + MapLayer::MAX_ORDER);
    new FieldWaymarkTerminalPresenter()->draw($camera, [['x' => 3, 'y' => 2, 'text' => 'W']]);
    $layers = array_column(Console::presentationSnapshot()->textLayers, 'layer', 'id');
    expect(Console::charAt(3, 2))->toBe('W')->and($layers[FieldWaymarkTerminalPresenter::LAYER])->toBe(PresentationLayerPolicy::FIELD_GUIDANCE);
    PresentationLayerPolicy::ui(new stdClass(), fn() => Console::write('U', 3, 2));
    expect(Console::charAt(3, 2))->toBe('U');
  } finally {
    foreach ($prior as $name => $value) { new ReflectionProperty(Console::class, $name)->setValue(null, $value); }
  }
});
