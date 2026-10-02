<?php

use Ichiloto\Engine\Animations\Field\FieldCueEdgePlacement;
use Ichiloto\Engine\Animations\Field\FieldEdgeSprite;
use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Field\FieldEffectSprite;
use Ichiloto\Engine\Animations\Field\FieldPieceEffects;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\EventCue;
use Ichiloto\Engine\Events\Triggers\EventCueKind;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Rendering\Camera;
use Tests\Support\Input\FakeRendererTransport;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function createFieldTimelineData(string $playback = 'loop', int $rest = 0): array
{
  $track = ['type' => 'image', 'asset' => 'Graphics/strip.png', 'sheet' => ['columns' => 8, 'rows' => 1],
    'cells' => ['width' => 2, 'height' => 2], 'keyframes' => array_map(
      static fn(int $frame): array => ['frame' => $frame, 'sourceFrame' => $frame], range(0, 7))];
  return ['fps' => 5, 'lengthFrames' => 8, 'playback' => $playback, 'restFrame' => $rest,
    'tracks' => [['id' => 'ground', 'depth' => 'behind', ...$track], ['id' => 'motes', 'depth' => 'front', ...$track]]];
}

function createFieldCue(string $kind = 'story', int $x = 100, int $y = 7, array $conditions = []): ScriptEventTrigger
{
  return new ScriptEventTrigger(new Rect($x, $y, 1, 1), ['mode' => 'action', 'reusable' => false],
    mapId: 'test-map', marker: 'E', cue: ['symbol' => '!', 'color' => 'bright-yellow', 'kind' => $kind, 'conditions' => $conditions]);
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-field-effects-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  $this->debugState = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
  writeTestPng($this->root . '/Graphics/strip.png', 16, 2);
  writeTestPng($this->root . '/Graphics/edge.png', 3, 3);
  mkdir($this->root . '/Animations/energy', 0777, true);
  file_put_contents($this->root . '/Animations/energy/energy.timeline.php', '<?php return ' . var_export(createFieldTimelineData(), true) . ';');
  mkdir($this->root . '/Data/Presentation', 0777, true);
  $edges = array_fill_keys(FieldPresentationCatalog::DIRECTIONS, ['asset' => 'Graphics/edge.png']);
  $edges['west']['quarterTurns'] = 2;
  $binding = ['cues' => ['bright-yellow' => ['effect' => 'energy', 'edges' => $edges]]];
  file_put_contents($this->root . '/Data/Presentation/field.php', '<?php return ' . var_export($binding, true) . ';');
  $this->library = new EffectTimelineLibrary($this->root);
});

afterEach(function () {
  foreach ($this->debugState as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('uses the shared playhead at five fps and wraps after exactly 1.6 seconds', function () {
  $session = new FieldEffectSession('energy', FieldEffectAnchor::fromArray(['cell' => ['x' => 4, 'y' => 5]]), $this->library->load('energy'));
  $first = $session->getSprites(new Vector2(4, 5));
  $session->update(0.199, false);
  expect($session->playback->currentFrame)->toBe(0);
  $session->update(0.001, false);
  expect($session->playback->currentFrame)->toBe(1);
  $session->update(1.4, false);
  expect($session->playback->currentFrame)->toBe(0)->and($session->playback->traversal)->toBe(1);
  $next = $session->getSprites(new Vector2(4, 5));
  expect(array_map(fn($sprite) => $sprite->getGraphicalSpriteId(), $next))->toBe(array_map(fn($sprite) => $sprite->getGraphicalSpriteId(), $first))
    ->and($next[0]->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2]);
});

it('draws a character between ground light and front motes without moving collision', function () {
  $session = new FieldEffectSession('energy', FieldEffectAnchor::fromArray(['cell' => ['x' => 4, 'y' => 5]]), $this->library->load('energy'));
  $sprites = $session->getSprites(new Vector2(4, 5));
  $behind = $sprites[0]->getGraphicalSpriteDefinition();
  $front = $sprites[1]->getGraphicalSpriteDefinition();
  expect([$behind->width, $behind->height])->toBe([96, 96])
    ->and($behind->layer)->toBeLessThan(0)->and($front->layer)->toBeGreaterThan(0)->and($front->layer)->toBeLessThan(900)
    ->and($sprites[0]->getGraphicalSpriteWorldPosition())->toEqual(new Vector2(4, 5))
    ->and($session->anchor->cell)->toEqual(new Vector2(4, 5));
});

it('holds an authored rest frame under reduced motion instead of advancing its loop', function () {
  $timeline = $this->library->compile('calm', createFieldTimelineData(rest: 3));
  $session = new FieldEffectSession('calm', FieldEffectAnchor::fromArray(['cell' => ['x' => 0, 'y' => 0]]), $timeline);
  $session->update(100, true);
  expect($session->playback->currentFrame)->toBe(0)
    ->and($session->getSprites(new Vector2(0, 0), true)[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6);
});

it('holds the rest frame for once field effects under reduced motion until their authored lifetime ends', function () {
  $session = new FieldEffectSession('once', FieldEffectAnchor::fromArray(['object' => 'player']),
    $this->library->compile('once', createFieldTimelineData('once', 3)));
  $session->update(0.1, true);
  expect($session->getSprites(new Vector2(2, 3), true)[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6);
  $session->update(1.4, true);
  expect($session->playback->isCompleted)->toBeFalse()
    ->and($session->getSprites(new Vector2(2, 3), true)[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6);
  $session->update(0.1, true);
  expect($session->playback->isCompleted)->toBeTrue()->and($session->getSprites(new Vector2(2, 3), true))->toBe([]);
});

it('starts concurrent map effects and clears the previous map on transfer and explicit clear', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $entry = ['id' => 'save', 'effect' => 'energy', 'anchor' => ['cell' => ['x' => 2, 'y' => 3]]];
  $manager->installMap('first', [$entry], null, []);
  $first = $manager->getSprites([], null, ['x' => 0, 'y' => 0], false);
  expect($first)->toHaveCount(2)->and($first[0]->getGraphicalSpriteId())->toContain('first');
  $manager->installMap('second', [], null, []);
  expect($manager->count)->toBe(0)->and($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toBe([]);
  $manager->installMap('second', [$entry], null, []);
  $manager->clear();
  expect($manager->count)->toBe(0);
});

it('renders terminal map effects without opening their graphical images or changing ground occupancy', function () {
  $data = ['presentations' => [
    'terminal' => ['fps' => 5, 'lengthFrames' => 2, 'restFrame' => 0, 'tracks' => [[
      'id' => 'glyph', 'type' => 'glyph', 'keyframes' => [
        ['frame' => 0, 'content' => '*', 'color' => 'cyan'], ['frame' => 1, 'content' => '+'],
      ],
    ]]],
    'graphical' => [...createFieldTimelineData(), 'tracks' => [[
      'id' => 'image', 'type' => 'image', 'asset' => 'Graphics/missing.png',
      'keyframes' => [['frame' => 0, 'duration' => 8]],
    ]]],
  ]];
  file_put_contents($this->root . '/Animations/energy/energy.timeline.php', '<?php return ' . var_export($data, true) . ';');
  $manager = new FieldEffectManager($this->root, EffectPresentation::TERMINAL);
  $manager->setCapabilities(false, false);
  $anchor = ['cell' => ['x' => 2, 'y' => 3]];
  $manager->installMap('terminal', [['id' => 'aura', 'effect' => 'energy', 'anchor' => $anchor]], null, []);
  $draws = [];
  $camera = $this->getMockBuilder(Camera::class)->disableOriginalConstructor()->onlyMethods(['getScreenSpacePosition', 'draw'])->getMock();
  new ReflectionProperty(Camera::class, 'screen')->setValue($camera, new Rect(0, 0, 20, 10));
  $camera->method('getScreenSpacePosition')->willReturnCallback(static fn(Vector2 $point): Vector2 => clone $point);
  $camera->method('draw')->willReturnCallback(static function ($content, $x, $y) use (&$draws): void { $draws[] = [$content, $x, $y]; });
  expect($manager->count)->toBe(1);
  $manager->renderText($camera, [], false);
  expect($draws)->toBe([['<fg=cyan>*</>', 2, 3]])
    ->and($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toBeEmpty();
  $manager->update(.2, false);
  $draws = [];
  $manager->renderText($camera, [], false);
  expect($draws)->toBe([['+', 2, 3]]);
  $draws = [];
  $manager->renderText($camera, [], true);
  expect($draws)->toBe([['<fg=cyan>*</>', 2, 3]]);
  $manager->clear();
  $draws = [];
  $manager->update(100, false);
  $manager->renderText($camera, [], false);
  expect($manager->count)->toBe(0)->and($draws)->toBeEmpty()
    ->and($anchor)->toBe(['cell' => ['x' => 2, 'y' => 3]]);
});

it('retains authoritative prompt and cue glyphs when a bound timeline has no graphical images', function () {
  $data = ['fps' => 5, 'lengthFrames' => 1, 'tracks' => [[
    'id' => 'glyph', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => '*']],
  ]]];
  file_put_contents($this->root . '/Animations/energy/energy.timeline.php', '<?php return ' . var_export($data, true) . ';');
  file_put_contents($this->root . '/Data/Presentation/field.php', '<?php return ' . var_export([
    'actionPrompt' => ['effect' => 'energy'], 'cues' => ['bright-yellow' => ['effect' => 'energy']],
  ], true) . ';');
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $cue = createFieldCue();
  $manager->installMap('glyph-only', [], null, [$cue]);
  expect($manager->count)->toBe(1)->and($manager->canPresentCue($cue))->toBeFalse()
    ->and($manager->canPresentActionPrompt())->toBeFalse();
});

it('does not make directional edge PNGs dependencies of terminal cue bindings', function () {
  unlink($this->root . '/Graphics/edge.png');
  $catalog = FieldPresentationCatalog::load($this->root, EffectPresentation::TERMINAL);
  expect($catalog->cues['bright-yellow']['effect'])->toBe('energy')
    ->and($catalog->cues['bright-yellow']['edges']['west']['quarterTurns'])->toBe(2);
  expect(fn() => FieldPresentationCatalog::load($this->root))->toThrow(RuntimeException::class);
});

it('follows a stable object anchor and retires it when its object leaves', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->installMap('map', [['id' => 'aura', 'effect' => 'energy', 'anchor' => ['object' => 'player']]], null, []);
  $definition = new GraphicalSpriteDefinition('Graphics/edge.png', 48, 48);
  $one = new FieldEffectSprite('player', $definition, new Vector2(2, 3));
  $two = new FieldEffectSprite('player', $definition, new Vector2(7, 9));
  $before = $manager->getSprites([$one], null, ['x' => 0, 'y' => 0], false);
  $after = $manager->getSprites([$two], null, ['x' => 0, 'y' => 0], false);
  expect($after[0]->getGraphicalSpriteId())->toBe($before[0]->getGraphicalSpriteId())
    ->and($after[0]->getGraphicalSpriteWorldPosition())->toEqual(new Vector2(7, 9));
  expect($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toBe([])->and($manager->count)->toBe(0);
});

it('retires an object effect during PHP update even without graphical rendering', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->installMap('map', [['id' => 'aura', 'effect' => 'energy', 'anchor' => ['object' => 'player']]], null, []);
  expect($manager->count)->toBe(1);
  $manager->update(0.2, false, []);
  expect($manager->count)->toBe(0);
});

it('clears all field effects on scene shutdown, including repeated shutdown', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->installMap('map', [['id' => 'light', 'effect' => 'energy', 'anchor' => ['cell' => ['x' => 0, 'y' => 0]]]], null, []);
  $scene = makeBareScene(GameScene::class);
  $camera = $this->getMockBuilder(Camera::class)->disableOriginalConstructor()->onlyMethods(['stop'])->getMock();
  $camera->expects($this->once())->method('stop');
  $events = $this->getMockBuilder(EventManager::class)->disableOriginalConstructor()->onlyMethods(['removeEventListener'])->getMock();
  $events->expects($this->once())->method('removeEventListener');
  new ReflectionProperty(GameScene::class, 'fieldEffects')->setValue($scene, $manager);
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
  new ReflectionProperty(GameScene::class, 'eventManager')->setValue($scene, $events);
  new ReflectionProperty(GameScene::class, 'modalEventHandler')->setValue($scene, static function (): void {});
  $scene->stop();
  $scene->stop();
  expect($manager->count)->toBe(0);
});

it('retains track identities and updates only source frames, then removes tracks on clear', function (bool $rotation) {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Effects', $this->root, protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => ['sprite_source_rect', ...($rotation ? ['sprite_quarter_turns'] : [])]], JSON_THROW_ON_ERROR))];
  $client->pump();
  $sender = new RetainedPresentation($client);
  $session = new FieldEffectSession('energy', FieldEffectAnchor::fromArray(['cell' => ['x' => 2, 'y' => 3]]), $this->library->load('energy'));
  $projector = new GraphicalSpriteProjector();
  $camera = new Camera(makeCameraTestScene(), 30, 15);
  $project = fn() => array_map(fn($sprite) => $projector->project($sprite, $camera), $session->getSprites(new Vector2(2, 3)));
  $edge = new PresentationSprite('edge', 'Graphics/edge.png', 0, 0, 48, 48, quarterTurns: 2);
  $sender->present(new ConsolePresentationChanges(30, 15, true, order: []), [...$project(), $edge], null);
  $initial = array_values(array_filter(end($transport->sent)->payload['operations'], fn($op) => ($op['kind'] ?? null) === 'sprite'));
  $byId = array_column($initial, 'value', 'id');
  expect($initial)->toHaveCount(3)
    ->and($byId['edge']['quarterTurns'] ?? null)->toBe($rotation ? 2 : null);
  $transport->sent = [];
  $session->update(0.2);
  $sender->present(new ConsolePresentationChanges(30, 15, false), [...$project(), $edge], null);
  $changes = end($transport->sent)->payload['operations'];
  expect($changes)->toHaveCount(2)
    ->and(array_column($changes, 'id'))->toBe(['energy:ground', 'energy:motes'])
    ->and($changes[0]['value']['sourceRect']['x'])->toBe(2)
    ->and(array_column($changes, 'op'))->toBe(['put', 'put']);
  $sender->present(new ConsolePresentationChanges(30, 15, false), [], null);
  expect(array_column(end($transport->sent)->payload['operations'], 'op'))->toBe(['remove', 'remove', 'remove']);
})->with([false, true]);

it('shares cue conditions and keeps terminal symbol and color untouched', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $cue = createFieldCue(conditions: [['type' => 'event', 'name' => 'ready']]);
  $state = new GameState();
  $cue->bind($state);
  $manager->installMap('map', null, null, [$cue]);
  expect($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toBe([]);
  $state->recordStoryEvent('ready');
  expect($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toHaveCount(2)
    ->and($cue->cue->styledSymbol())->toBe('<fg=bright-yellow>!</>');
  $cue->complete();
  expect($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toBe([]);
});

it('pins story edges but leaves route cues at their authored cell', function () {
  $view = new FieldViewport(new RendererGridConfig(135, 36, 10, 20));
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->installMap('map', null, null, [createFieldCue()]);
  $edges = $manager->getSprites([], $view, ['x' => 0, 'y' => 0], false);
  expect($edges)->toHaveCount(1)->and($edges[0])->toBeInstanceOf(FieldEdgeSprite::class);
  $manager->installMap('map', null, null, [createFieldCue('route')]);
  $routes = $manager->getSprites([], $view, ['x' => 0, 'y' => 0], false);
  expect($routes)->toHaveCount(2)->and($routes[0])->not->toBeInstanceOf(FieldEdgeSprite::class)
    ->and($routes[0]->getGraphicalSpriteWorldPosition()->x)->toBe(100.0);
  $sprites = [new PresentationSprite('player', 'Graphics/edge.png', 2, 3, 48, 48), new PresentationSprite('edge', 'Graphics/edge.png', 100, 5, 48, 48)];
  expect($view->createViewport([], $sprites, screenSpriteIds: ['edge'])->spriteIds)->toBe(['player']);
});

it('places straight and diagonal edge images in all eight directions', function (int $x, int $y, string $direction) {
  $view = new FieldViewport(new RendererGridConfig(135, 36, 10, 20));
  $placement = FieldCueEdgePlacement::locate(new Vector2($x, $y), ['x' => 0, 'y' => 0], $view);
  expect($placement['direction'])->toBe($direction)
    ->and($placement['position']->x)->toBeGreaterThanOrEqual(0)->toBeLessThan(135)
    ->and($placement['position']->y)->toBeGreaterThanOrEqual(0)->toBeLessThan(36);
})->with([[100, 7, 'east'], [100, 100, 'southeast'], [13, 100, 'south'], [-100, 100, 'southwest'],
  [-100, 7, 'west'], [-100, -100, 'northwest'], [13, -100, 'north'], [100, -100, 'northeast']]);

it('retains cell cue artwork when an edge image is missing or its rotation is unsupported', function (bool $missing) {
  if ($missing) {
    file_put_contents($this->root . '/Data/Presentation/field.php', '<?php return ' . var_export([
      'cues' => ['bright-yellow' => ['effect' => 'energy', 'edges' => []]],
    ], true) . ';');
  }
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, false);
  $cue = createFieldCue('story', 0, 7);
  $manager->installMap('map', [], null, [$cue]);
  $view = new FieldViewport(new RendererGridConfig(135, 36, 10, 20));
  $sprites = $manager->getSprites([], $view, ['x' => 100, 'y' => 0], false);
  expect($manager->canPresentCue($cue))->toBeTrue()->and($sprites)->toHaveCount(2)
    ->and($sprites[0])->not->toBeInstanceOf(FieldEdgeSprite::class)
    ->and($sprites[0]->getGraphicalSpriteWorldPosition())->toEqual(new Vector2(0, 7))
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('cue art remains at its cell');
})->with([false, true]);

it('keeps glyphs and static tiles usable when effect art or renderer capabilities are absent', function () {
  $manager = new FieldEffectManager($this->root);
  $cue = createFieldCue();
  $manager->installMap('map', [], null, [$cue]);
  $manager->setCapabilities(false, false);
  expect($manager->canPresentCue($cue))->toBeFalse()->and($manager->getSprites([], null, ['x' => 0, 'y' => 0], false))->toBe([]);
  $manager->startEffect('missing', 'not-found', FieldEffectAnchor::fromArray(['cell' => ['x' => 0, 'y' => 0]]));
  expect($manager->count)->toBe(1);
});

it('logs capability fallback once when effects start after capability detection', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(false, false);
  $manager->installMap('map', [], null, [createFieldCue()]);
  $manager->installMap('next-map', [], null, [createFieldCue()]);
  expect(substr_count(file_get_contents($this->root . '/warning.log'), 'cannot draw field effects'))->toBe(1);
});

it('spawns every graphical save piece independently of its terminal glyph', function () {
  $tileset = Tileset::fromArray('test', ['name' => 'Test', 'sheets' => ['B' => 'Graphics/edge.png'],
    'pieces' => ['save' => ['name' => 'Save', 'layer' => 'fixtures', 'glyphs' => ['?'], 'tiles' => ['props' => ['56']], 'effect' => 'energy']]]);
  $graphics = new MapGraphics($tileset, [new MapTileLayer('props', 0, 'test', '56 0 56')]);
  $effects = FieldPieceEffects::find($graphics);
  expect($effects)->toHaveCount(2)->and(array_column($effects, 'anchor'))->toBe([
    ['cell' => ['x' => 0, 'y' => 0]], ['cell' => ['x' => 2, 'y' => 0]]]);
});

it('validates explicit cue kinds without assigning older or disabled cues', function () {
  expect(EventCue::fromArray(['symbol' => '!'])->kind)->toBeNull()
    ->and(EventCue::fromArray(['symbol' => '!', 'kind' => 'story'])->kind)->toBe(EventCueKind::STORY)
    ->and(EventCue::fromArray(['symbol' => '', 'kind' => 'route']))->toBeNull()
    ->and(fn() => EventCue::fromArray(['symbol' => '!', 'kind' => 'yellow']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => EventCue::fromArray(['symbol' => '', 'kind' => 'yellow']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => EventCue::fromArray(['symbol' => '!', 'kind' => true]))->toThrow(InvalidArgumentException::class);
});

/** Binds an action prompt timeline that opens over frames 0 to 2 and idles from frame 3, drawn a cell above its object. */
function writeFieldActionPrompt(string $root): void
{
  $timeline = createFieldTimelineData(rest: 3);
  $timeline['loopFrom'] = 3;
  $timeline['tracks'] = [['id' => 'balloon', 'type' => 'image', 'asset' => 'Graphics/strip.png', 'sheet' => ['columns' => 8, 'rows' => 1],
    'depth' => 'front', 'keyframes' => array_map(static fn(int $frame): array =>
      ['frame' => $frame, 'sourceFrame' => $frame, 'position' => ['x' => 0, 'y' => -1]], range(0, 7))]];
  mkdir($root . '/Animations/balloon', 0777, true);
  file_put_contents($root . '/Animations/balloon/balloon.timeline.php', '<?php return ' . var_export($timeline, true) . ';');
  file_put_contents($root . '/Data/Presentation/field.php', '<?php return ' . var_export(['actionPrompt' => ['effect' => 'balloon']], true) . ';');
}

it('opens a loop once, then repeats it from its loop frame', function () {
  $timeline = createFieldTimelineData();
  $timeline['loopFrom'] = 3;
  $session = new FieldEffectSession('balloon', FieldEffectAnchor::fromArray(['object' => 'player']), $this->library->compile('balloon', $timeline));
  $frames = [];
  for ($step = 0; $step < 12; $step++) { $session->update(0.2, false); $frames[] = $session->playback->currentFrame; }
  expect($frames)->toBe([1, 2, 3, 4, 5, 6, 7, 3, 4, 5, 6, 7])->and($session->playback->traversal)->toBe(1);
  $session->playback->restart();
  expect($session->playback->currentFrame)->toBe(0);

  foreach ([['once', 3], ['loop', 8], ['loop', -1], ['loop', '3']] as [$playback, $loopFrom]) {
    $invalid = createFieldTimelineData($playback);
    $invalid['loopFrom'] = $loopFrom;
    expect(fn() => $this->library->compile('bad', $invalid))->toThrow(InvalidArgumentException::class, 'loopFrom must be a frame of a looping effect');
  }
  expect(new FieldEffectSession('energy', FieldEffectAnchor::fromArray(['cell' => ['x' => 0, 'y' => 0]]),
    $this->library->load('energy'))->playback->loopFrom)->toBe(0);
});

it('reads the action prompt binding beside the cue bindings', function () {
  expect(FieldPresentationCatalog::fromArray(['actionPrompt' => ['effect' => 'balloon']], $this->root)->actionPrompt)->toBe('balloon')
    ->and(FieldPresentationCatalog::fromArray(['cues' => []], $this->root)->actionPrompt)->toBeNull();
  foreach ([['effect' => 'Balloon!'], ['effect' => 'balloon', 'asset' => 'x.png'], 'balloon', ['effect' => 3]] as $binding) {
    expect(fn() => FieldPresentationCatalog::fromArray(['actionPrompt' => $binding], $this->root))->toThrow(InvalidArgumentException::class);
  }
});

it('draws the action prompt over the object that can act, opening once however often it is shown', function () {
  writeFieldActionPrompt($this->root);
  $manager = new FieldEffectManager($this->root);
  expect($manager->canPresentActionPrompt())->toBeFalse();
  $manager->setCapabilities(true, false);
  expect($manager->canPresentActionPrompt())->toBeTrue();

  $player = new FieldEffectSprite('player', new GraphicalSpriteDefinition('Graphics/edge.png', 48, 48), new Vector2(4, 6));
  $manager->showActionPrompt('player');
  $manager->update(0.4, false, [$player]);
  $manager->showActionPrompt('player');
  $manager->update(0.2, false, [$player]);
  $sprites = $manager->getSprites([$player], null, ['x' => 0, 'y' => 0], false);
  // Redrawn while it lasts, it keeps playing: frame 3, a cell above the player, in front of characters.
  expect($sprites)->toHaveCount(1)
    ->and($sprites[0]->getGraphicalSpriteId())->toBe('field-effect::' . FieldEffectManager::ACTION_PROMPT_ID . ':balloon')
    ->and($sprites[0]->getGraphicalSpriteWorldPosition())->toEqual(new Vector2(4, 5))
    ->and($sprites[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6)
    ->and($sprites[0]->getGraphicalSpriteDefinition()->layer)->toBe(PresentationLayerPolicy::FIELD_EFFECT_FRONT)
    // Reduced motion shows the open balloon at rest.
    ->and($manager->getSprites([$player], null, ['x' => 0, 'y' => 0], true)[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6);

  $manager->showActionPrompt(null);
  expect($manager->count)->toBe(0);
  $manager->showActionPrompt('player');
  expect($manager->getSprites([$player], null, ['x' => 0, 'y' => 0], false)[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
});

it('keeps the prompt glyph when the action prompt is unbound or its effect cannot load', function () {
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->showActionPrompt('player');
  expect($manager->canPresentActionPrompt())->toBeFalse()->and($manager->count)->toBe(0);

  file_put_contents($this->root . '/Data/Presentation/field.php', '<?php return ' . var_export(['actionPrompt' => ['effect' => 'missing']], true) . ';');
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  expect($manager->canPresentActionPrompt())->toBeFalse()->and($manager->canPresentActionPrompt())->toBeFalse()
    ->and(substr_count(file_get_contents($this->root . '/warning.log'), 'Action prompt effect missing is unusable'))->toBe(1);
});

it('refuses invalid anchors, missing images, frame grids and overlapping tracks', function () {
  expect(fn() => FieldEffectAnchor::fromArray(['cell' => ['x' => -1, 'y' => 2]]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => FieldEffectManager::readDeclarations([['id' => 'a', 'effect' => 'energy', 'anchor' => []]]))->toThrow(InvalidArgumentException::class);
  $invalid = createFieldTimelineData();
  $invalid['tracks'][0]['sheet']['columns'] = 3;
  expect(fn() => $this->library->compile('bad', $invalid))->toThrow(InvalidArgumentException::class);
  $invalid = createFieldTimelineData();
  $invalid['tracks'][0]['keyframes'][0]['duration'] = 2;
  expect(fn() => $this->library->compile('bad', $invalid))->toThrow(InvalidArgumentException::class);
  $invalid = createFieldTimelineData();
  $invalid['tracks'][0]['asset'] = 'Graphics/missing.png';
  expect(fn() => $this->library->compile('bad', $invalid))->toThrow(RuntimeException::class);
});
