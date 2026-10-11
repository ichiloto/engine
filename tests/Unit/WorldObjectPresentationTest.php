<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicSubjectResolver;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class WorldObjectTestGame extends Game
{
    public function __construct() { $this->audioManager = new RecordingAudioManager($this); }
    public function __destruct() {}
}

final class WorldObjectTestMap extends MapManager
{
    public ?PreparedMap $destination = null;
    public function __construct(Game $game, GameScene $scene) { parent::__construct($game, $scene); }
    public function prepareMap(string $filename): PreparedMap { return $this->destination ?? parent::prepareMap($filename); }
    public function getCollisionGrid(): array { return $this->collisionMap; }
}

final class WorldObjectTestPlayer extends Player
{
    protected function renderLocationHUDWindow(): void {}
    public function renderEventCues(): void {}
}

final class WorldObjectTestScene extends GameScene
{
    private Game $testGame;
    public function __construct(string $root)
    {
        $this->testGame = new WorldObjectTestGame();
        $this->testGame->useRendererRuntime(new RendererRuntime(new RendererRuntimeConfig(
            new RendererProcessConfig(['not-launched']), $root . '/assets'), new FakeRendererTransport()));
        $this->sceneManager = makeBareScene(SceneManager::class);
        $this->sceneManager->currentScene = $this;
        $this->gameState = new GameState();
        $this->party = new Party();
        $this->uiManager = new class extends UIManager { public function __construct() {} };
        $this->currentMapId = 'synthetic/room';
        $this->camera = new class($this, 12, 6) extends Camera { public function stop(): void {} };
        $this->npcManager = new NpcManager($this);
        $this->mapManager = new WorldObjectTestMap($this->testGame, $this);
        $this->cinematicStage = new CinematicStageManager($this);
        $this->cinematicPresentation = new CinematicPresentationManager($this);
        $this->cinematicController = new CinematicController($this);
        $presentation = new class implements EventPresentationInterface {
            public function beginText(string $text, string $name = ''): void {}
            public function beginChoice(string $prompt, array $options, string $title = ''): void {}
            public function update(): void {}
            public function render(): void {}
            public function isComplete(): bool { return false; }
            public function choiceResult(): ?int { return null; }
            public function reset(): void {}
        };
        $this->eventInterpreter = new EventInterpreter($this, $presentation);
        $this->player = new WorldObjectTestPlayer($this, 'hero', new Vector2(0, 0), new Rect(0, 0, 1, 1), ['@'], MovementHeading::SOUTH);
        new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
        $this->eventManager = EventManager::getInstance($this->testGame);
        $this->modalEventHandler = static function (): void {};
    }
    public function getGame(): Game { return $this->testGame; }
    public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
    {
        $this->requestFieldPresentationReconciliation();
    }
}

function createWorldObjectSource(array $extra = []): array
{
    return array_replace(['id' => 'fixture', 'anchor' => ['x' => 1, 'y' => 1], 'pivot' => ['x' => .25, 'y' => .75],
        'sprites2d' => ['asset' => 'Graphics/base.png', 'cells' => ['width' => 2, 'height' => 2]],
        'variants' => [
            ['id' => 'absent', 'conditions' => [['type' => 'switch', 'name' => 'removed']], 'sprites2d' => null],
            ['id' => 'energized', 'conditions' => [['type' => 'event', 'name' => 'activated']],
                'sprites2d' => ['asset' => 'Graphics/energized.png']],
        ],
        'covers' => ['layer' => 'fixtures', 'cells' => [[1, 1]], 'tileLayers' => ['objects']],
    ], $extra);
}

function createWorldObjectPreparedMap(array $objects, string $id = 'synthetic/room', ?FieldPresentationCatalog $catalog = null): PreparedMap
{
    $layers = new MapLayerSet([
        new MapLayer('ground', 0, false, 'ground', "gggg\ngggg\ngggg"),
        new MapLayer('fixtures', 10, false, 'fixtures', "    \n oo \n    "),
        new MapLayer('signs', 20, false, 'signs', "    \n  s \n    "),
    ]);
    $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['B' => 'Graphics/tiles.png'], 'above' => [2]]);
    $graphics = new MapGraphics($tileset, [
        new MapTileLayer('floor', 0, 'floor', "1 1 1 1\n1 1 1 1\n1 1 1 1"),
        new MapTileLayer('objects', 10, 'objects', "0 0 0 0\n0 2 2 0\n0 0 0 0"),
    ], owners: ['floor' => 'ground', 'objects' => 'fixtures']);
    $data = ['id' => $id, 'name' => 'Synthetic room', 'worldObjects' => $objects];
    return new PreparedMap($data, $layers->getComposedGrid(), array_fill(0, 3, array_fill(0, 4, CollisionType::COLLECTABLE->value)),
        [], [], [], $layers, $graphics, WorldObjectDefinition::readMap($data, $layers, $graphics, catalog: $catalog));
}

function createWorldObjectCinematic(array $commands = [], array $extra = []): CinematicDefinition
{
    return CinematicDefinition::fromArrays(array_replace(['id' => 'synthetic-lease', 'name' => 'Synthetic lease',
        'cast' => [['id' => 'glow', 'subject' => ['kind' => 'world_object', 'id' => 'fixture'],
            'sprites2d' => ['asset' => 'Graphics/glow.png', 'animation' => ['columns' => 2, 'frames' => [0, 1], 'fps' => 10]]]],
        'skip' => ['policy' => 'authored'],
        'finalizer' => [['type' => 'record_event', 'name' => 'activated']],
    ], $extra), $commands ?: [['type' => 'wait', 'seconds' => 10]]);
}

function createResourceWorldObjectCatalog(string $root, array $extra = []): FieldPresentationCatalog
{
    return FieldPresentationCatalog::fromArray(['resources' => ['whole' => array_replace([
        'name' => 'Synthetic reusable object', 'pivot' => ['x' => .25, 'y' => .75],
        'sprites2d' => ['asset' => 'Graphics/base.png', 'cells' => ['width' => 3, 'height' => 4], 'layer' => 100],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]],
    ], $extra)]], $root . '/assets');
}

function getWorldObjectWireCells(PresentationWorld $world): array
{
    $rows = [];
    foreach ($world->operations as $operation) {
        if ($operation['op'] === 'worldRows') {
            foreach ($operation['rows'] as $row) { $rows[$row['row']] = $row['cells']; }
        }
    }
    return $rows;
}

/** Real broker with a fake transport; sign consumers must never pump it themselves. */
function createWorldSignHost(WorldObjectTestScene $scene, string $root, bool $acceptWrites = true): array
{
    writeTestPng($root . '/assets/Graphics/board.png', 32, 20);
    mkdir($root . '/assets/Data/Presentation', 0700, true);
    file_put_contents($root . '/assets/Data/Presentation/text.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.text/1', 'roles' => ['sign' => ['fonts' => [['asset' => 'Fonts/replaceable.ttf']],
            'fontSize' => 12, 'foreground' => ['kind' => 'rgb', 'r' => 20, 'g' => 30, 'b' => 40]]]], true) . ';');
    $catalog = FieldPresentationCatalog::fromArray(['resources' => ['board' => [
        'name' => 'Synthetic board', 'pivot' => ['x' => .5, 'y' => 1],
        'sign' => ['asset' => 'Graphics/board.png', 'fontRole' => 'sign', 'left' => 4, 'right' => 4,
            'density' => 1, 'padding' => [2, 2, 2, 2], 'minimumWidth' => 20, 'maximumWidth' => 100, 'repeatWidth' => 4],
    ]]], $root . '/assets');
    $source = ['id' => 'board-placement', 'resource' => 'board', 'anchor' => ['x' => 1, 'y' => 1],
        'label' => ['message' => 'signs.workshop', 'default' => 'Workshop'],
        'covers' => ['layer' => 'fixtures', 'cells' => [[1, 1]], 'tileLayers' => []]];
    $map = createWorldObjectPreparedMap([$source], catalog: $catalog);
    $scene->mapManager->applyPreparedMap($map, $scene->player);
    $transport = new FakeRendererTransport();
    $transport->acceptWrites = $acceptWrites;
    $client = new RendererClient($transport);
    $session = new RendererSessionConfig('Synthetic signs', $root . '/assets', protocol: RendererProtocolVersion::V2);
    $client->start($session);
    $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["text_layout"]}')];
    $client->pump();
    $client->drainEvents();
    $scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(12, 6, 16, 24),
        static fn(string $cap): bool => true, assetRoot: $root . '/assets', textPreparation: $client));
    return [$client, $transport, $scene->mapManager->findWorldObject('board-placement'), $session];
}

function getWorldSignQueries(FakeRendererTransport $transport): array
{
    return array_values(array_filter($transport->sent,
        static fn($message) => $message->type === \Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType::PREPARE_TEXT));
}

function completeWorldSignQuery(RendererClient $client, FakeRendererTransport $transport, string $layout = 'board-text',
    float $width = 30, float $height = 10, ?string $failure = null): void
{
    $query = getWorldSignQueries($transport);
    $request = $query[array_key_last($query)]->payload['requestId'];
    $data = $failure !== null ? ['type' => 'text_prepare_failed', 'requestId' => $request, 'message' => $failure]
        : ['type' => 'text_prepared', 'requestId' => $request, 'layoutId' => $layout,
            'metrics' => ['advance' => $width, 'baseline' => 9,
                'lineBounds' => ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height],
                'inkBounds' => ['x' => -1, 'y' => 1, 'width' => $width, 'height' => $height],
                'paintBounds' => ['x' => -1, 'y' => 1, 'width' => $width, 'height' => $height]]];
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, ...$data], JSON_THROW_ON_ERROR))];
    $client->pump();
}

/** Write only synthetic authored sources under the test's external scratch root. */
function writeWorldObjectLoadingSources(string $root, array $data, bool $includeGraphics = true): array
{
    $directory = $root . '/assets/Maps/synthetic/loading';
    mkdir($directory . '/layers', 0777, true);
    $sources = [
        $root . '/assets/Maps/collisions.php' => '<?php return ["g" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::COLLECTABLE,
            "o" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::COLLECTABLE,
            "s" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::COLLECTABLE];',
        $directory . '/loading.data.php' => '<?php /* preserve authored declarations */ return ' . var_export(['name' => 'Loading', ...$data], true) . ';',
        $directory . '/loading.event.php' => MapGridSource::buildSource("    \n    \n    ", 'EVENT'),
        $directory . '/layers/00.ground.map.php' => MapGridSource::buildSource("gggg\ngggg\ngggg", 'MAP'),
        $directory . '/layers/10.fixtures.map.php' => MapGridSource::buildSource("    \n oo \n    ", 'MAP'),
        $directory . '/layers/20.signs.map.php' => MapGridSource::buildSource("    \n  s \n    ", 'MAP'),
    ];
    if ($includeGraphics) {
        mkdir($directory . '/graphics');
        $sources[$directory . '/graphics/00.floor.tiles.php'] = MapGridSource::buildSource("1 1 1 1\n1 1 1 1\n1 1 1 1", 'TILES');
        $sources[$directory . '/graphics/10.objects.tiles.php'] = MapGridSource::buildSource("0 0 0 0\n0 2 2 0\n0 0 0 0", 'TILES');
    }
    foreach ($sources as $path => $source) { file_put_contents($path, $source); }
    return $sources;
}

beforeEach(function () {
    $this->before = [];
    foreach ([Console::class, Cursor::class, EventManager::class, Debug::class, ConfigStore::class] as $class) {
        $this->before[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->cwd = getcwd();
    $this->root = createTestDirectory('ichiloto-world-objects-');
    chdir($this->root);
    foreach (['base' => [12, 10], 'energized' => [8, 4], 'glow' => [10, 10], 'tiles' => [32, 32]] as $name => [$width, $height]) {
        writeTestPng($this->root . '/assets/Graphics/' . $name . '.png', $width, $height);
    }
    Debug::configure(['log_directory' => $this->root . '/logs']);
    putSceneAudioConfig(['ui' => ['hud' => ['location' => false]]]);
    ob_start();
    Console::syncDimensions(12, 6);
    $this->scene = new WorldObjectTestScene($this->root);
    $this->prepared = createWorldObjectPreparedMap([createWorldObjectSource()]);
    $this->scene->mapManager->applyPreparedMap($this->prepared, $this->scene->player);
    $this->object = $this->scene->mapManager->findWorldObject('fixture');
});

afterEach(function () {
    chdir($this->cwd);
    ob_end_clean();
    foreach ($this->before as $class => $state) {
        foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
});

it('prepares localized world signs without polling and overlays the existing ground without changing gameplay', function () {
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root);
    $before = [$this->scene->mapManager->tileMap, $this->scene->gameState->toArray(), $this->scene->mapManager->getCollisionGrid()];
    $polls = $transport->polls;
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and($sign->hidesOwnedGlyphs())->toBeFalse()
        ->and($sign->getGraphicalSpriteDefinition())->toBeNull()->and(getWorldSignQueries($transport))->toHaveCount(1)
        ->and($transport->polls)->toBe($polls);
    completeWorldSignQuery($client, $transport);
    $polls = $transport->polls;
    $groups = $this->scene->getPresentationWorldCanvases();
    $origin = $this->scene->camera->getWorldOrigin();
    expect($groups)->toHaveCount(1)->and([$groups[0]->x, $groups[0]->y])->toBe([1 - $origin['x'], 1 - $origin['y']])
        ->and($groups[0]->id)->toBe($sign->getGraphicalSpriteId())->and($groups[0]->canvas->labels[0]->measurement->layoutId)->toBe('board-text')
        ->and($sign->hidesOwnedGlyphs())->toBeTrue()->and($transport->polls)->toBe($polls)
        ->and([$this->scene->mapManager->tileMap, $this->scene->gameState->toArray(), $this->scene->mapManager->getCollisionGrid()])->toBe($before);
    $world = $this->scene->getPresentationWorld();
    $tiles = array_values(array_filter($world->operations, static fn($op) => $op['op'] === 'worldTiles'));
    expect($tiles)->not->toBe([]); // A board claiming a glyph does not implicitly erase building textures.
    $sign->release();
    expect($sign->getPresentationWorldCanvas())->toBeNull();
});

it('waits through transport backpressure and resizes the whole measured label without truncation', function () {
    [$client, $transport] = createWorldSignHost($this->scene, $this->root, acceptWrites: false);
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and(getWorldSignQueries($transport))->toBe([]);
    $transport->acceptWrites = true;
    $this->scene->getPresentationWorldCanvases();
    completeWorldSignQuery($client, $transport, width: 192);
    expect($this->scene->getPresentationWorldCanvases())->toBe([]);
    $this->scene->getPresentationWorldCanvases();
    $queries = getWorldSignQueries($transport);
    expect($queries)->toHaveCount(2)->and($queries[1]->payload['spec']['text'])->toBe('Workshop')
        ->and($queries[1]->payload['spec']['fontSize'])->toBe(6.0);
    completeWorldSignQuery($client, $transport, 'fitted', width: 96);
    $group = $this->scene->getPresentationWorldCanvases()[0];
    expect($group->canvas->width)->toBe(100)->and($group->canvas->labels[0]->measurement->layoutId)->toBe('fitted');
});

it('releases old labels on locale and current font replacement without changing sign identity', function () {
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root);
    $id = $sign->getGraphicalSpriteId();
    $this->scene->getPresentationWorldCanvases();
    completeWorldSignQuery($client, $transport);
    expect($this->scene->getPresentationWorldCanvases())->toHaveCount(1);
    putSceneAudioConfig(['locale' => 'fr', 'messages' => ['fr' => ['signs' => ['workshop' => 'Atelier']]]]);
    expect($this->scene->getPresentationWorldCanvases())->toBe([]);
    $queries = getWorldSignQueries($transport);
    expect($queries)->toHaveCount(2)->and($queries[1]->payload['spec']['text'])->toBe('Atelier')
        ->and($queries[1]->payload['spec']['locale'])->toBe('fr');
    completeWorldSignQuery($client, $transport, 'translated');
    expect($this->scene->getPresentationWorldCanvases())->toHaveCount(1);
    mkdir($this->root . '/assets/Fonts');
    file_put_contents($this->root . '/assets/Fonts/replaceable.ttf', 'replacement font facts, decoding owned by renderer');
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and(getWorldSignQueries($transport))->toHaveCount(3)
        ->and($sign->getGraphicalSpriteId())->toBe($id);
});

it('diagnoses an unchanged refusal once and retries in a new renderer session', function () {
    [$client, $transport, $sign, $session] = createWorldSignHost($this->scene, $this->root);
    $firstSession = $client->getTextPreparationSession();
    $this->scene->getPresentationWorldCanvases();
    completeWorldSignQuery($client, $transport, failure: 'Synthetic missing glyph');
    for ($i = 0; $i < 3; $i++) { expect($this->scene->getPresentationWorldCanvases())->toBe([]); }
    expect(getWorldSignQueries($transport))->toHaveCount(1)->and($sign->hidesOwnedGlyphs())->toBeFalse();
    $client->start($session);
    $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["text_layout"]}')];
    $client->pump();
    $client->drainEvents();
    expect($client->getTextPreparationSession())->not->toBe($firstSession);
    $this->scene->getPresentationWorldCanvases();
    expect(getWorldSignQueries($transport))->toHaveCount(2);
    completeWorldSignQuery($client, $transport, 'recovered');
    expect($this->scene->getPresentationWorldCanvases())->toHaveCount(1);
});

it('retires map and preview tickets and does not borrow a runtime for unbound or Terminal previews', function () {
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root);
    $this->scene->getPresentationWorldCanvases();
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(12, 6, 16, 24),
        static fn(string $cap): bool => true));
    expect($this->scene->getTextPreparationProvider())->toBeNull()->and($this->scene->getPresentationWorldCanvases())->toBe([]);
    completeWorldSignQuery($client, $transport, 'late');
    expect(array_column(array_map(static fn($message) => $message->payload, $transport->sent), 'layoutIds'))->toContain(['late']);
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(12, 6, 16, 24),
        static fn(string $cap): bool => true, graphical: false, textPreparation: $client));
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and($sign->hidesOwnedGlyphs())->toBeFalse();
    $this->scene->mapManager->applyPreparedMap($this->prepared, $this->scene->player);
    expect($sign->isCurrent())->toBeFalse()->and($sign->getPresentationWorldCanvas())->toBeNull();
});

it('recovers from invalid translated text and preserves glyphs while an asset is unavailable', function () {
    putSceneAudioConfig(['messages' => ['signs' => ['workshop' => "Invalid\nlabel"]]]);
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root);
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and(getWorldSignQueries($transport))->toBe([]);
    putSceneAudioConfig(['messages' => ['signs' => ['workshop' => 'Corrected']]]);
    $this->scene->getPresentationWorldCanvases();
    completeWorldSignQuery($client, $transport);
    expect($sign->hidesOwnedGlyphs())->toBeTrue();
    unlink($this->root . '/assets/Graphics/board.png');
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and($sign->hidesOwnedGlyphs())->toBeFalse();
    writeTestPng($this->root . '/assets/Graphics/board.png', 36, 24);
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and(getWorldSignQueries($transport))->toHaveCount(2);
    completeWorldSignQuery($client, $transport, 'replaced');
    expect($this->scene->getPresentationWorldCanvases())->toHaveCount(1);
});

it('requires every shared sign capability without erasing its Terminal owner', function (string $missing) {
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root, acceptWrites: false);
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(12, 6, 16, 24),
        static fn(string $cap): bool => $cap !== $missing, textPreparation: $client));
    $transport->acceptWrites = true;
    expect($this->scene->getTextPreparationProvider())->toBeNull()->and($this->scene->getPresentationWorldCanvases())->toBe([])
        ->and($sign->hidesOwnedGlyphs())->toBeFalse()->and(getWorldSignQueries($transport))->toBe([]);
})->with(['graphical_canvas', 'text_layout', 'canvas_labels', 'world_canvas', 'frame_viewport', 'sprite_source_rect', 'canvas_clip_opacity']);

it('retries bounded preparation capacity after other consumers retire their requests', function () {
    [$client, $transport] = createWorldSignHost($this->scene, $this->root, acceptWrites: false);
    $transport->acceptWrites = true;
    $spec = new \Ichiloto\Engine\Rendering\Presentation\Text\TextSpec('Occupied',
        [new \Ichiloto\Engine\Rendering\Presentation\Text\FontReference('Fonts/replaceable.ttf')],
        12, 'und', \Ichiloto\Engine\Rendering\Presentation\PresentationColor::rgb(20, 30, 40));
    $tickets = [];
    for ($i = 0; $i < 64; $i++) { $tickets[] = $client->requestTextPreparation($spec); }
    expect($this->scene->getPresentationWorldCanvases())->toBe([])->and(getWorldSignQueries($transport))->toHaveCount(64);
    foreach ($tickets as $ticket) { $client->releaseTextPreparation($ticket); }
    $transport->batches[] = array_map(static fn($ticket) => RendererEvent::fromJson(json_encode([
        'protocol' => 2, 'type' => 'text_prepare_failed', 'requestId' => $ticket->requestId, 'message' => 'Cancelled synthetic query',
    ], JSON_THROW_ON_ERROR)), $tickets);
    $client->pump();
    $this->scene->getPresentationWorldCanvases();
    expect(getWorldSignQueries($transport))->toHaveCount(65);
    completeWorldSignQuery($client, $transport, 'after-capacity');
    expect($this->scene->getPresentationWorldCanvases())->toHaveCount(1);
});

it('composes prepared signs through the shared scene path with camera-relative viewport membership', function () {
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root);
    completeWorldSignQuery($client, $transport);
    $composer = new \Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer($this->root . '/assets');
    $context = $this->scene->getPresentationContext();
    $frame = $composer->composeFrame($this->scene, $context->grid, $context->supports(...),
        static fn() => new \Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot(12, 6, []), refreshOverlay: false);
    expect($frame->worldCanvases)->toHaveCount(1)->and($frame->viewport->worldCanvasIds)->toBe([$sign->getGraphicalSpriteId()])
        ->and($frame->viewport->worldId)->toBe($frame->world->id);
    $this->scene->mapManager->clearWorldObjects();
    $cleared = $composer->composeFrame($this->scene, $context->grid, $context->supports(...),
        static fn() => new \Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot(12, 6, []), refreshOverlay: false);
    expect($cleared->worldCanvases)->toBe([])->and($cleared->viewport->worldCanvasIds)->toBe([]);
});

it('requires localized labels only for sign resources and refuses mixed presentation ownership', function () {
    [$client, $transport, $sign] = createWorldSignHost($this->scene, $this->root);
    $resource = $sign->definition->resource;
    $catalog = FieldPresentationCatalog::fromArray(['resources' => ['board' => [
        'name' => 'Board', 'pivot' => ['x' => .5, 'y' => 1], 'sign' => [
            'asset' => 'Graphics/board.png', 'fontRole' => 'sign', 'left' => 4, 'right' => 4, 'density' => 1,
            'padding' => [2, 2, 2, 2], 'minimumWidth' => 20, 'maximumWidth' => 100],
    ]]], $this->root . '/assets');
    $source = ['id' => 'another', 'resource' => 'board', 'anchor' => ['x' => 2, 'y' => 1],
        'label' => ['message' => 'synthetic.label', 'default' => 'Another']];
    foreach ([array_diff_key($source, ['label' => true]), [...$source, 'label' => 'not localized'],
        [...$source, 'sprites2d' => []], [...$source, 'variants' => []], [...createWorldObjectSource(), 'label' => $source['label']]] as $bad) {
        expect(fn() => WorldObjectDefinition::readMap(['worldObjects' => [$bad]], $this->prepared->layers,
            $this->prepared->graphics, catalog: $catalog))->toThrow(InvalidArgumentException::class);
    }
    expect($resource->sprites)->toBeNull()->and($resource->sign)->not->toBeNull()
        ->and($this->scene->mapManager->findWorldObject('board-placement'))->toBe($sign);
});

it('keeps absent declarations compatible and refuses malformed selectors before installing a map', function (string $invalid) {
    $entry = createWorldObjectSource();
    $objects = [$entry];
    match ($invalid) {
        'null list' => $objects = null,
        'duplicate identity' => $objects[] = $entry,
        'anchor' => $objects[0]['anchor']['x'] = -1,
        'pivot' => $objects[0]['pivot']['x'] = 2,
        'unsafe art' => $objects[0]['sprites2d']['asset'] = '../another.png',
        'unknown selector' => $objects[0]['variants'][0]['conditions'][0]['type'] = 'filename',
        'empty conditions' => $objects[0]['variants'][0]['conditions'] = [],
        'bad operator' => $objects[0]['variants'][0]['conditions'] = [['type' => 'variable', 'name' => 'phase', 'op' => 'like']],
        'duplicate variant' => $objects[0]['variants'][] = $objects[0]['variants'][0],
        'unknown owner' => $objects[0]['covers']['layer'] = 'missing',
        'unrelated tile layer' => $objects[0]['covers']['tileLayers'] = ['floor'],
        'duplicate cell' => $objects[0]['covers']['cells'][] = [1, 1],
        'unknown field' => $objects[0]['collision'] = true,
        'variant budget' => $objects[0]['variants'] = array_fill(0, 17, $objects[0]['variants'][0]),
        'null variants' => $objects[0]['variants'] = null,
        'overlapping ownership' => $objects[] = array_replace($entry, ['id' => 'other']),
        'object budget' => $objects = array_fill(0, 129, $entry),
        'invalid switch value' => $objects[0]['variants'][0]['conditions'][0]['value'] = 'false',
        'invalid negate' => $objects[0]['variants'][0]['conditions'][0]['negate'] = null,
        'invalid quest status' => $objects[0]['variants'][0]['conditions'] = [['type' => 'quest', 'name' => 'quest', 'status' => 'unknown']],
        'invalid variable value' => $objects[0]['variants'][0]['conditions'] = [['type' => 'variable', 'name' => 'phase', 'value' => []]],
        'invalid quantity' => $objects[0]['variants'][0]['conditions'] = [['type' => 'item', 'name' => 'token', 'quantity' => null]],
    };
    expect(WorldObjectDefinition::readMap([], $this->prepared->layers))->toBe([])
        ->and(fn() => WorldObjectDefinition::readMap(['worldObjects' => $objects], $this->prepared->layers, $this->prepared->graphics))
        ->toThrow(InvalidArgumentException::class)
        ->and($this->scene->mapManager->findWorldObject('fixture'))->toBe($this->object);
})->with(['null list', 'duplicate identity', 'anchor', 'pivot', 'unsafe art', 'unknown selector', 'empty conditions', 'bad operator',
    'duplicate variant', 'unknown owner', 'unrelated tile layer', 'duplicate cell', 'unknown field', 'variant budget',
    'null variants', 'overlapping ownership', 'object budget', 'invalid switch value', 'invalid negate', 'invalid quest status',
    'invalid variable value', 'invalid quantity']);

it('selects live persistent state without mutating terminal collision routes events or saves', function () {
    $before = [$this->scene->mapManager->tileMap, $this->scene->camera->worldSpace, $this->scene->gameState->toArray()];
    $identity = $this->object->getGraphicalSpriteId();
    expect($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png')
        ->and($this->scene->mapManager->canMoveTo(1, 1))->toBeTrue()
        ->and($this->scene->npcManager->npcAt(1, 1))->toBeNull()
        ->and([$this->scene->mapManager->tileMap, $this->scene->camera->worldSpace, $this->scene->gameState->toArray()])->toBe($before);
    $this->scene->gameState->recordStoryEvent('activated');
    expect($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png');
    $this->scene->gameState->setSwitch('removed', true);
    expect($this->object->getGraphicalSpriteDefinition())->toBeNull()->and($this->object->hidesOwnedGlyphs())->toBeTrue()
        ->and($this->object->getGraphicalSpriteId())->toBe($identity)
        ->and($this->scene->mapManager->tileMap)->toBe($before[0])->and($this->scene->mapManager->canMoveTo(1, 1))->toBeTrue();
    $state = $this->scene->gameState->toArray();
    $this->scene->mapManager->applyPreparedMap($this->prepared, $this->scene->player);
    $new = $this->scene->mapManager->findWorldObject('fixture');
    expect($new)->not->toBe($this->object)->and($new->getGraphicalSpriteId())->toBe($identity)
        ->and($new->getGraphicalSpriteDefinition())->toBeNull()->and($this->object->getGraphicalSpriteDefinition())->toBeNull()
        ->and($this->scene->gameState->toArray())->toBe($state);
});

it('projects the explicit ground pivot using current replacement dimensions and reconciles only the same crop', function () {
    $source = createWorldObjectSource(['sprites2d' => ['asset' => 'Graphics/base.png',
        'sourceRect' => ['x' => 2, 'y' => 1, 'width' => 10, 'height' => 9]]]);
    $this->scene->mapManager->applyPreparedMap(createWorldObjectPreparedMap([$source]), $this->scene->player);
    $object = $this->scene->mapManager->findWorldObject('fixture');
    $id = $object->getGraphicalSpriteId();
    writeTestPng($this->root . '/assets/Graphics/base.png', 8, 6);
    $frame = $object->getGraphicalSpriteDefinition();
    $projected = new GraphicalSpriteProjector()->project($object, $this->scene->camera);
    expect($frame->sourceRect->toArray())->toBe(['x' => 2, 'y' => 1, 'width' => 6, 'height' => 5])
        ->and($projected->pivot->toArray())->toBe(['x' => .25, 'y' => .75])
        ->and([$frame->width, $frame->height])->toBe([48, 48])->and($object->position)->toEqual(new Vector2(1, 1))
        ->and($object->getGraphicalSpriteId())->toBe($id)
        ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('Reconciled stale world-object crop');
    writeTestPng($this->root . '/assets/Graphics/base.png', 1, 1);
    expect($object->getGraphicalSpriteDefinition())->toBeNull()->and($object->hidesOwnedGlyphs())->toBeFalse();
});

it('updates pose crops from replaceable files and owns animation without adding a movement subject', function () {
    $source = createWorldObjectSource(['sprites2d' => ['asset' => 'Graphics/base.png',
        'animation' => ['columns' => 2, 'frames' => [0, 1], 'fps' => 10]]]);
    $this->scene->mapManager->applyPreparedMap(createWorldObjectPreparedMap([$source]), $this->scene->player);
    $object = $this->scene->mapManager->findWorldObject('fixture');
    $object->advanceGraphicalAnimation(.1);
    expect($object->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6);
    writeTestPng($this->root . '/assets/Graphics/base.png', 20, 10);
    expect($object->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(10)
        ->and($object->getGraphicalSpriteMotion())->toBeNull()->and($object->position)->toEqual(new Vector2(1, 1));
});

it('covers only declared glyph and static fixture cells and preserves independent ground and other owners', function () {
    $world = $this->scene->mapManager->getPresentationWorld();
    $cells = getWorldObjectWireCells($world);
    expect($cells[1][1])->toMatchArray(['glyph' => 'g', 'ownerLayerId' => 'map:ground'])
        ->and($cells[1][2])->toMatchArray(['glyph' => 's', 'ownerLayerId' => 'map:signs'])
        ->and($this->scene->mapManager->getPresentationWorld())->toBe($world)
        ->and($this->prepared->graphics->layers[1]->tiles[1][1])->toBe(2);
    $tiles = array_values(array_filter($world->operations, fn($op) => $op['op'] === 'worldTiles'));
    $floor = array_values(array_filter($tiles, fn($op) => $op['layerId'] === 'tiles:floor'));
    $objects = array_values(array_filter($tiles, fn($op) => $op['layerId'] === 'tiles:objects:above'));
    expect($floor[1]['rows'][0]['cells'])->toHaveCount(4)
        ->and(array_column($objects[0]['rows'][0]['cells'], 'column'))->toBe([2]);
    unlink($this->root . '/assets/Graphics/base.png');
    $fallback = $this->scene->mapManager->getPresentationWorld();
    expect($fallback)->not->toBe($world)->and(getWorldObjectWireCells($fallback)[1][1]['glyph'])->toBe('o')
        ->and($this->object->getGraphicalSpriteDefinition())->toBeNull()
        ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('Graphics/base.png');
    writeTestPng($this->root . '/assets/Graphics/base.png', 7, 9);
    expect(getWorldObjectWireCells($this->scene->mapManager->getPresentationWorld())[1][1]['glyph'])->toBe('g');
});

it('validates the shared cinematic world-object selector and refuses unknown or independent movement bindings', function () {
    expect(CinematicCommandSchema::SUBJECT_KINDS)->toContain('world_object');
    CinematicScriptValidator::validate([['type' => 'camera', 'operation' => 'focus', 'target' => ['kind' => 'world_object', 'id' => 'fixture']]], 'synthetic');
    expect(new CinematicSubjectResolver($this->scene)->position(['kind' => 'world_object', 'id' => 'fixture']))->toEqual(new Vector2(1, 1))
        ->and(fn() => new CinematicSubjectResolver($this->scene)->position(['kind' => 'world_object', 'id' => 'missing']))->toThrow(RuntimeException::class)
        ->and(fn() => CinematicStageManager::validateBinding(['subject' => ['kind' => 'world_object']]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => CinematicScriptValidator::validate([['type' => 'move_route', 'subject' => 'world_object', 'steps' => [['direction' => 'right']]]], 'synthetic'))
        ->toThrow(InvalidArgumentException::class);
});

it('releases temporary replacement on every exit and never rolls back physical unlocks or persistent variants', function (string $ending) {
    $commands = $ending === 'error' ? [['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'missing', 'steps' => [['direction' => 'right']]]]
        : [['type' => 'wait', 'seconds' => 10]];
    $session = $this->scene->startCinematic(createWorldObjectCinematic($commands));
    $stage = $this->scene->cinematicStage;
    $actor = $stage->find('glow');
    if ($ending !== 'error') {
        expect($stage->suppresses($this->object))->toBeTrue()->and($actor->sprite)->toBe([])
            ->and($actor->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/glow.png')
            ->and($actor->getGraphicalSpriteDefinition()->pivot)->toEqual($this->object->definition->pivot);
        $stage->advanceGraphicalAnimation(.1);
        expect($actor->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 5, 'y' => 0, 'width' => 5, 'height' => 10]);
        $this->scene->gameState->recordStoryEvent('activated');
        $this->scene->gameState->setSwitch('unlocked', true);
        $this->scene->mapManager->destination = createWorldObjectPreparedMap([createWorldObjectSource()], 'synthetic/other');
        match ($ending) {
            'complete' => $this->scene->updateEventSession(11),
            'skip' => $this->scene->skipCinematic(),
            'cancel' => $this->scene->eventInterpreter->failActiveSession('Synthetic cancellation.'),
            'failure' => $this->scene->eventInterpreter->failActiveSession('Synthetic runtime failure.'),
            'transfer' => $this->scene->loadMap('synthetic/other', $this->scene->player),
            'shutdown' => $this->scene->stop(),
        };
        $actor->show();
        expect($actor->getGraphicalSpriteDefinition())->toBeNull()->and($this->scene->gameState->getSwitch('unlocked'))->toBeTrue();
    }
    expect($stage->all())->toBe([])->and($stage->suppresses($this->object))->toBeFalse();
    if ($ending === 'transfer' || $ending === 'shutdown') {
        expect($this->object->isCurrent())->toBeFalse();
    } elseif ($ending !== 'error') {
        expect($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png')
            ->and($this->scene->hasUnstableEventSession())->toBeFalse();
    } else {
        expect($session->failureMessage)->not->toBeNull()->and($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png');
    }
})->with(['complete', 'skip', 'cancel', 'failure', 'error', 'transfer', 'shutdown']);

it('keeps hidden leases scoped and re-evaluates state after replacement removal and same-id map reload', function () {
    $stage = $this->scene->cinematicStage;
    $actor = $stage->add(['id' => 'glow', 'subject' => ['kind' => 'world_object', 'id' => 'fixture']]);
    expect($actor->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png');
    $stage->hide('glow');
    expect($stage->suppresses($this->object))->toBeTrue()->and($stage->hidesWorldObjectGlyphs($this->object))->toBeTrue();
    $this->scene->gameState->recordStoryEvent('activated');
    $stage->remove('glow');
    expect($stage->suppresses($this->object))->toBeFalse()->and($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png');
    $actor = $stage->add(['id' => 'glow', 'subject' => ['kind' => 'world_object', 'id' => 'fixture']]);
    $this->scene->mapManager->applyPreparedMap($this->prepared, $this->scene->player);
    $new = $this->scene->mapManager->findWorldObject('fixture');
    expect($stage->suppresses($new))->toBeFalse()->and($actor->getGraphicalSpriteDefinition())->toBeNull()
        ->and($new->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png');
    $stage->clear();
});

it('keeps snapshots and terminal output identical when the optional declaration is removed', function () {
    $snapshot = serialize($this->scene->createSnapshot());
    Console::recomposeFrame(function () { $this->scene->mapManager->render(); });
    $terminal = Console::presentationSnapshot();
    $plain = createWorldObjectPreparedMap([]);
    $this->scene->mapManager->applyPreparedMap($plain, $this->scene->player);
    Console::recomposeFrame(function () { $this->scene->mapManager->render(); });
    expect(serialize($this->scene->createSnapshot()))->toBe($snapshot)
        ->and(Console::presentationSnapshot())->toEqual($terminal)
        ->and($this->scene->mapManager->getPresentationWorld()->operations)
        ->toBe(PresentationWorld::getFromLayers($plain->layers, 'map', $plain->graphics, $this->root . '/assets')->operations);
});

it('refuses malformed actual map source before mutation and keeps authored condition arrays intact', function () {
    $directory = $this->root . '/assets/Maps/synthetic/source';
    mkdir($directory, 0777, true);
    file_put_contents($this->root . '/assets/Maps/collisions.php', '<?php return [];');
    $source = createWorldObjectSource();
    $source['covers']['tileLayers'] = [];
    $source['covers']['layer'] = 'terrain';
    $data = ['name' => 'Source', 'worldObjects' => [$source]];
    file_put_contents($directory . '/source.map.php', "<?php return <<<'GRID'\n;;;;\n;o;;\n;;;;\nGRID;");
    file_put_contents($directory . '/source.event.php', "<?php return <<<'GRID'\n    \n    \n    \nGRID;");
    $path = $directory . '/source.data.php';
    $authored = '<?php /* authored presentation */ return ' . var_export($data, true) . ';';
    file_put_contents($path, $authored);
    $prepared = $this->scene->mapManager->prepareMap('synthetic/source');
    expect($prepared->worldObjects[0]->variants[0]['conditions'])->toBe($source['variants'][0]['conditions'])
        ->and(file_get_contents($path))->toBe($authored)->and($this->scene->mapManager->findWorldObject('fixture'))->toBe($this->object);
    $data['worldObjects'][0]['variants'][0]['conditions'][0]['type'] = 'unsupported';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    expect(fn() => $this->scene->loadMap('synthetic/source', $this->scene->player))->toThrow(InvalidArgumentException::class)
        ->and($this->scene->currentMapId)->toBe('synthetic/room')
        ->and($this->scene->mapManager->findWorldObject('fixture'))->toBe($this->object);
});

it('loads actual maps with unavailable optional graphics with or without objects and applies no unproven coverage', function (string $failure, bool $withObjects) {
    $data = ['tileset' => 'missing', 'tileLayers' => ['floor' => ['movesWith' => 'ground']]];
    if ($withObjects) { $data['worldObjects'] = [createWorldObjectSource()]; }
    if ($failure === 'missing graphics') { unset($data['tileset'], $data['tileLayers']); }
    $sources = writeWorldObjectLoadingSources($this->root, $data, $failure !== 'missing graphics');
    if ($failure === 'malformed graphics') {
        $path = $this->root . '/assets/Maps/synthetic/loading/graphics/10.objects.tiles.php';
        $sources[$path] = '<?php file_put_contents(' . var_export($this->root . '/executed', true) . ', "bad"); return "0";';
        file_put_contents($path, $sources[$path]);
    }
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    $manager = $this->scene->mapManager;
    $object = $manager->findWorldObject('fixture');
    expect($this->scene->currentMapId)->toBe('synthetic/loading')->and($manager->graphics)->toBeNull()
        ->and($manager->canMoveTo(1, 1))->toBeTrue()->and($manager->tileMap[1])->toBe(['g', 'o', 's', 'g'])
        ->and($this->scene->npcManager->npcAt(1, 1))->toBeNull()->and(is_file($this->root . '/executed'))->toBeFalse();
    $cells = getWorldObjectWireCells($manager->getPresentationWorld());
    expect($cells[1][1])->toMatchArray(['glyph' => 'o', 'ownerLayerId' => 'map:fixtures'])
        ->and($cells[1][2])->toMatchArray(['glyph' => 's', 'ownerLayerId' => 'map:signs'])
        ->and($cells[1][3])->toMatchArray(['glyph' => 'g', 'ownerLayerId' => 'map:ground']);
    if ($withObjects) {
        expect($object->isCurrent())->toBeTrue()->and($object->definition->coverage)->toBe([])
            ->and($object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png')
            ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('coverage ownership is unavailable');
    } else { expect($object)->toBeNull(); }
    foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }

    // Optional subjects must not change the already loaded map's terminal output, collision or save snapshot.
    $snapshot = serialize($this->scene->createSnapshot());
    $collisions = $manager->getCollisionGrid();
    Console::recomposeFrame(function () use ($manager) { $manager->render(); });
    $terminal = Console::presentationSnapshot();
    unset($data['worldObjects']);
    $path = $this->root . '/assets/Maps/synthetic/loading/loading.data.php';
    file_put_contents($path, '<?php return ' . var_export(['name' => 'Loading', ...$data], true) . ';');
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    Console::recomposeFrame(function () use ($manager) { $manager->render(); });
    expect(serialize($this->scene->createSnapshot()))->toBe($snapshot)->and($manager->getCollisionGrid())->toBe($collisions)
        ->and(Console::presentationSnapshot())->toEqual($terminal)->and($manager->findWorldObject('fixture'))->toBeNull();
})->with(['missing tileset', 'missing graphics', 'malformed graphics'])->with([true, false]);

it('resolves explicit ownership through the shared contract even when the optional tileset is missing', function () {
    $data = ['tileset' => 'missing', 'tileLayers' => ['floor' => ['movesWith' => 'ground'], 'objects' => ['movesWith' => 'fixtures']],
        'worldObjects' => [createWorldObjectSource()]];
    $sources = writeWorldObjectLoadingSources($this->root, $data);
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    $manager = $this->scene->mapManager;
    $object = $manager->findWorldObject('fixture');
    $cells = getWorldObjectWireCells($manager->getPresentationWorld());
    expect($manager->graphics)->toBeNull()->and($object->definition->coverage['tiles']['objects'][1][1])->toBeTrue()
        ->and($cells[1][1])->toMatchArray(['glyph' => 'g', 'ownerLayerId' => 'map:ground'])
        ->and($cells[1][2])->toMatchArray(['glyph' => 's', 'ownerLayerId' => 'map:signs'])
        ->and($cells[1][3])->toMatchArray(['glyph' => 'g', 'ownerLayerId' => 'map:ground'])
        ->and($manager->canMoveTo(1, 1))->toBeTrue();
    unlink($this->root . '/assets/Graphics/base.png');
    expect(getWorldObjectWireCells($manager->getPresentationWorld())[1][1]['glyph'])->toBe('o');
    foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }
});

it('keeps unrelated optional tile settings out of objects that declare no tile coverage', function () {
    $entry = createWorldObjectSource();
    unset($entry['covers']);
    $sources = writeWorldObjectLoadingSources($this->root, ['tileset' => 'missing', 'tileLayers' => ['objects' => ['movesWith' => 'unknown']],
        'worldObjects' => [$entry]]);
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    expect($this->scene->mapManager->graphics)->toBeNull()
        ->and($this->scene->mapManager->findWorldObject('fixture')->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png')
        ->and(getWorldObjectWireCells($this->scene->mapManager->getPresentationWorld())[1][1]['glyph'])->toBe('o');
    foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }
});

it('rebuilds inferred ownership and current variants on reload after optional tileset restoration without rewriting map source', function () {
    $sources = writeWorldObjectLoadingSources($this->root, ['tileset' => 'missing', 'tileLayers' => ['floor' => ['movesWith' => 'ground']],
        'worldObjects' => [createWorldObjectSource()]]);
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    $old = $this->scene->mapManager->findWorldObject('fixture');
    expect($old->definition->coverage)->toBe([]);
    $this->scene->gameState->recordStoryEvent('activated');
    $directory = $this->root . '/assets/Data/Tilesets';
    mkdir($directory, 0777, true);
    file_put_contents($directory . '/missing.php', '<?php return ' . var_export([
        'name' => 'Restored', 'sheets' => ['B' => 'Graphics/tiles.png'],
        'pieces' => ['fixture' => ['name' => 'Fixture', 'layer' => 'fixtures', 'glyphs' => ['o'], 'tiles' => ['objects' => ['2']]]],
    ], true) . ';');
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    $object = $this->scene->mapManager->findWorldObject('fixture');
    expect($object)->not->toBe($old)->and($old->isCurrent())->toBeFalse()
        ->and($this->scene->mapManager->graphics->owners['objects'])->toBe('fixtures')
        ->and($object->definition->coverage['tiles']['objects'][1][1])->toBeTrue()
        ->and($object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png')
        ->and($this->scene->mapManager->canMoveTo(1, 1))->toBeTrue();
    $cells = getWorldObjectWireCells($this->scene->mapManager->getPresentationWorld());
    expect($cells[1][1])->toMatchArray(['glyph' => 'g', 'ownerLayerId' => 'map:ground'])
        ->and($cells[1][2])->toMatchArray(['glyph' => 's', 'ownerLayerId' => 'map:signs']);
    foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }
});

it('refuses provably malformed or unknown object ownership without loading the destination despite missing optional art', function (string $invalid) {
    $entry = createWorldObjectSource();
    $objects = [$entry];
    $settings = ['floor' => ['movesWith' => 'ground']];
    match ($invalid) {
        'unknown tile layer' => $objects[0]['covers']['tileLayers'] = ['unknown'],
        'wrong tile owner' => $objects[0]['covers']['tileLayers'] = ['floor'],
        'unknown gameplay owner' => $objects[0]['covers']['layer'] = 'unknown',
        'malformed tile reference' => $objects[0]['covers']['tileLayers'] = ['../objects'],
        'duplicate tile reference' => $objects[0]['covers']['tileLayers'] = ['objects', 'objects'],
        'overlapping unresolved ownership' => $objects[] = array_replace($entry, ['id' => 'other']),
        'malformed explicit owner' => $settings['objects'] = ['movesWith' => 'unknown'],
    };
    $sources = writeWorldObjectLoadingSources($this->root, ['tileset' => 'missing', 'tileLayers' => $settings, 'worldObjects' => $objects]);
    $before = [$this->scene->mapManager->tileMap, $this->scene->mapManager->getCollisionGrid(), serialize($this->scene->createSnapshot())];
    expect(fn() => $this->scene->loadMap('synthetic/loading', $this->scene->player))->toThrow(InvalidArgumentException::class)
        ->and($this->scene->currentMapId)->toBe('synthetic/room')
        ->and($this->scene->mapManager->findWorldObject('fixture'))->toBe($this->object)
        ->and([$this->scene->mapManager->tileMap, $this->scene->mapManager->getCollisionGrid(), serialize($this->scene->createSnapshot())])->toBe($before);
    foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }
})->with(['unknown tile layer', 'wrong tile owner', 'unknown gameplay owner', 'malformed tile reference',
    'duplicate tile reference', 'overlapping unresolved ownership', 'malformed explicit owner']);

it('suppresses multiple declared subjects without consuming unrelated ground or a third object', function () {
    $second = createWorldObjectSource(['id' => 'second', 'anchor' => ['x' => 2, 'y' => 1],
        'covers' => ['layer' => 'fixtures', 'cells' => [[2, 1]], 'tileLayers' => ['objects']]]);
    $third = createWorldObjectSource(['id' => 'third', 'anchor' => ['x' => 3, 'y' => 1], 'covers' => ['layer' => 'ground', 'cells' => [[3, 1]], 'tileLayers' => []]]);
    $this->scene->mapManager->applyPreparedMap(createWorldObjectPreparedMap([createWorldObjectSource(), $second, $third]), $this->scene->player);
    $first = $this->scene->mapManager->findWorldObject('fixture');
    $second = $this->scene->mapManager->findWorldObject('second');
    $third = $this->scene->mapManager->findWorldObject('third');
    $stage = $this->scene->cinematicStage;
    $stage->add(['id' => 'paired', 'subject' => ['kind' => 'world_object', 'id' => 'fixture'],
        'suppress' => [['kind' => 'world_object', 'id' => 'second']], 'sprites2d' => ['asset' => 'Graphics/glow.png']]);
    expect($stage->suppresses($first))->toBeTrue()->and($stage->suppresses($second))->toBeTrue()->and($stage->suppresses($third))->toBeFalse();
    $cells = getWorldObjectWireCells($this->scene->mapManager->getPresentationWorld());
    expect($cells[1][1]['glyph'])->toBe('g')->and($cells[1][2]['glyph'])->toBe('s');
    $stage->clear();
    expect($stage->suppresses($first))->toBeFalse()->and($stage->suppresses($second))->toBeFalse();
});

it('emits one field subject during a lease and restores only its current selected role on release', function () {
    $context = new ScenePresentationContext(new RendererGridConfig(12, 6), static fn(string $capability): bool => true,
        assetRoot: $this->root . '/assets');
    $this->scene->setPresentationContext($context);
    $collect = function (): array {
        return array_values(array_filter(iterator_to_array($this->scene->getGraphicalSpriteProviders()),
            fn($provider) => str_starts_with($provider->getGraphicalSpriteId(), 'world-object:') || str_starts_with($provider->getGraphicalSpriteId(), 'staged:')));
    };
    expect($collect())->toBe([$this->object]);
    $actor = $this->scene->cinematicStage->add(['id' => 'glow', 'subject' => ['kind' => 'world_object', 'id' => 'fixture']]);
    expect($collect())->toBe([$actor]);
    $this->scene->gameState->recordStoryEvent('activated');
    expect($actor->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png');
    $this->scene->cinematicStage->clear();
    expect($collect())->toBe([$this->object])->and($collect()[0]->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/energized.png');
    $this->scene->setPresentationContext(null);
});

it('refuses duplicate suppression and restores useful glyphs when an explicit replacement asset is invalid', function () {
    $stage = $this->scene->cinematicStage;
    $binding = ['id' => 'bad-art', 'subject' => ['kind' => 'world_object', 'id' => 'fixture'],
        'sprites2d' => ['asset' => 'Graphics/missing.png']];
    $stage->add($binding);
    expect($stage->hidesWorldObjectGlyphs($this->object))->toBeFalse()
        ->and(getWorldObjectWireCells($this->scene->mapManager->getPresentationWorld())[1][1]['glyph'])->toBe('o')
        ->and(fn() => $stage->add(array_replace($binding, ['id' => 'duplicate'])))->toThrow(RuntimeException::class);
    $stage->clear();
    expect($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png');
    $binding['sprites2d'] = ['asset' => 'Graphics/glow.png'];
    $stage->add($binding);
    expect(fn() => $stage->configure([$binding, array_replace($binding, ['id' => 'duplicate'])]))->toThrow(RuntimeException::class)
        ->and($stage->all())->toBe([])->and($stage->suppresses($this->object))->toBeFalse()
        ->and($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png');
});

it('releases replaced pose clocks and retains pause hide and clear ownership for the replacement', function () {
    $stage = $this->scene->cinematicStage;
    $binding = createWorldObjectCinematic()->cast[0];
    $first = $stage->add($binding);
    $stage->advanceGraphicalAnimation(.1);
    expect($first->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(5);
    $stage->pauseGraphicalAnimation();
    $stage->advanceGraphicalAnimation(10);
    expect($first->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(5);
    $second = $stage->add([...$binding, 'replace' => true]);
    expect($first->getGraphicalSpriteDefinition())->toBeNull()->and($second->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
    $stage->resumeGraphicalAnimation();
    $stage->advanceGraphicalAnimation(.1);
    $stage->hide('glow');
    $stage->advanceGraphicalAnimation(10);
    $stage->show('glow');
    expect($second->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(5);
    $stage->clear();
    $second->show();
    $second->resumeGraphicalAnimation();
    $second->advanceGraphicalAnimation(100);
    expect($second->getGraphicalSpriteDefinition())->toBeNull()->and($this->object->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/base.png');
});

it('refuses unknown conflicting or unsafe resource placements before actual map installation', function (string $invalid) {
    $entry = ['id' => 'placed', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1],
        'covers' => ['layer' => 'fixtures', 'cells' => [[1, 1]], 'tileLayers' => []]];
    $resource = ['name' => 'Synthetic', 'pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => ['asset' => 'Graphics/base.png'],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]]];
    match ($invalid) {
        'unknown resource' => $entry['resource'] = 'unknown',
        'null resource' => $entry['resource'] = null,
        'inline pivot' => $entry['pivot'] = ['x' => .5, 'y' => 1],
        'inline role' => $entry['sprites2d'] = null,
        'inline variants' => $entry['variants'] = [],
        'anchor' => $entry['anchor']['x'] = 4,
        'recipe type' => $resource['occupancyStamp'][0]['type'] = 1,
        'unsafe path' => $resource['sprites2d']['asset'] = '../outside.png',
        'pivot' => $resource['pivot']['y'] = -1,
        'decoration coverage' => $entry['covers']['layer'] = 'canopy',
    };
    $sources = writeWorldObjectLoadingSources($this->root, ['worldObjects' => [$entry]], false);
    mkdir($this->root . '/assets/Data/Presentation', 0777, true);
    $path = $this->root . '/assets/Data/Presentation/field.php';
    $sources[$path] = '<?php return ' . var_export(['resources' => ['whole' => $resource]], true) . ';';
    file_put_contents($path, $sources[$path]);
    $before = [$this->scene->mapManager->tileMap, $this->scene->mapManager->getCollisionGrid(), serialize($this->scene->createSnapshot())];
    expect(fn() => $this->scene->loadMap('synthetic/loading', $this->scene->player))->toThrow(InvalidArgumentException::class)
        ->and($this->scene->currentMapId)->toBe('synthetic/room')
        ->and($this->scene->mapManager->findWorldObject('fixture'))->toBe($this->object)
        ->and([$this->scene->mapManager->tileMap, $this->scene->mapManager->getCollisionGrid(), serialize($this->scene->createSnapshot())])->toBe($before);
    foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }
})->with(['unknown resource', 'null resource', 'inline pivot', 'inline role', 'inline variants', 'anchor',
    'recipe type', 'unsafe path', 'pivot', 'decoration coverage']);

it('refuses out of map authoring stamps without invalidating runtime placements when the recipe widens', function () {
    $entries = [['id' => 'edge-placement', 'resource' => 'whole', 'anchor' => ['x' => 0, 'y' => 0]]];
    $occupancy = array_fill(0, 3, array_fill(0, 4, CollisionType::NONE));
    $occupancy[0][1] = CollisionType::COUNTER;
    $sources = writeWorldObjectLoadingSources($this->root, ['worldObjects' => $entries, 'occupancy' => $occupancy], false);
    mkdir($this->root . '/assets/Data/Presentation', 0777, true);
    $path = $this->root . '/assets/Data/Presentation/field.php';
    $resource = ['name' => 'Synthetic', 'pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => ['asset' => 'Graphics/base.png'],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]]];
    file_put_contents($path, '<?php return ' . var_export(['resources' => ['whole' => $resource]], true) . ';');
    $manager = $this->scene->mapManager;
    $prepared = $manager->prepareMap('synthetic/loading');
    expect($prepared->worldObjects[0]->resource->resolveOccupancyStamp($prepared->layers, 0, 0))
        ->toBe([['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]]);
    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    $old = $manager->findWorldObject('edge-placement');
    $before = [$manager->tileMap, $manager->getCollisionGrid(), serialize($this->scene->createSnapshot()),
        serialize($this->scene->eventManager->activeEvents)];
    Console::recomposeFrame(fn() => $manager->render());
    $terminal = Console::presentationSnapshot();

    $resource['occupancyStamp'][] = ['x' => -1, 'y' => 0, 'type' => CollisionType::SOLID];
    $sources[$path] = '<?php return ' . var_export(['resources' => ['whole' => $resource]], true) . ';';
    file_put_contents($path, $sources[$path]);
    $wider = $manager->prepareMap('synthetic/loading');
    expect($wider->data['occupancy'])->toBe($occupancy)
        ->and($wider->worldObjects[0]->resource->occupancyStamp)->toBe($resource['occupancyStamp'])
        ->and($wider->worldObjects[0]->resource)->not->toBe($prepared->worldObjects[0]->resource);
    $writeAttempted = false;
    $dataPath = $this->root . '/assets/Maps/synthetic/loading/loading.data.php';
    $authorStamp = function () use ($wider, $dataPath, &$writeAttempted): void {
        $cells = $wider->worldObjects[0]->resource->resolveOccupancyStamp($wider->layers, 0, 0);
        $writeAttempted = true;
        file_put_contents($dataPath, var_export($cells, true));
    };
    expect($authorStamp)->toThrow(InvalidArgumentException::class)
        ->and($writeAttempted)->toBeFalse();
    foreach ($sources as $sourcePath => $source) { expect(file_get_contents($sourcePath))->toBe($source); }

    $this->scene->loadMap('synthetic/loading', $this->scene->player);
    $current = $manager->findWorldObject('edge-placement');
    Console::recomposeFrame(fn() => $manager->render());
    expect($current->getGraphicalSpriteId())->toBe($old->getGraphicalSpriteId())
        ->and($current->getGraphicalSpriteDefinition())->not->toBeNull()
        ->and($old->isCurrent())->toBeFalse()
        ->and([$manager->tileMap, $manager->getCollisionGrid(), serialize($this->scene->createSnapshot()),
            serialize($this->scene->eventManager->activeEvents)])->toBe($before)
        ->and($manager->getCollisionGrid()[0][0])->toBe(CollisionType::NONE->value)
        ->and($manager->getCollisionGrid()[0][1])->toBe(CollisionType::COUNTER->value)
        ->and(Console::presentationSnapshot())->toEqual($terminal);
    foreach ($sources as $sourcePath => $source) { expect(file_get_contents($sourcePath))->toBe($source); }
});

it('loads a catalog once per preparation and keeps inline source resource identity and occupancy independent', function () {
    $entries = [
        ['id' => 'placed-one', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1]],
        ['id' => 'placed-two', 'resource' => 'whole', 'anchor' => ['x' => 2, 'y' => 1]],
        createWorldObjectSource(['id' => 'inline']),
    ];
    $occupancy = array_fill(0, 3, array_fill(0, 4, CollisionType::NONE));
    $occupancy[1][1] = CollisionType::COUNTER;
    $sources = writeWorldObjectLoadingSources($this->root, ['worldObjects' => $entries, 'occupancy' => $occupancy], false);
    mkdir($this->root . '/assets/Data/Presentation', 0777, true);
    $path = $this->root . '/assets/Data/Presentation/field.php';
    $sources[$path] = '<?php $GLOBALS["g4ResourceCatalogLoads"]++; return ' . var_export(['resources' => ['whole' => [
        'name' => 'Reusable', 'pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => ['asset' => 'Graphics/base.png'],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]],
    ]]], true) . ';';
    file_put_contents($path, $sources[$path]);
    $GLOBALS['g4ResourceCatalogLoads'] = 0;
    try {
        $prepared = $this->scene->mapManager->prepareMap('synthetic/loading');
        expect($GLOBALS['g4ResourceCatalogLoads'])->toBe(1)
            ->and($prepared->worldObjects[0]->resource)->toBe($prepared->worldObjects[1]->resource)
            ->and($prepared->worldObjects[2]->resource)->toBeNull()
            ->and($prepared->data['worldObjects'])->toBe($entries)
            ->and($prepared->collisions[1][1])->toBe(CollisionType::COUNTER->value)
            ->and($prepared->collisions[1][2])->toBe(CollisionType::NONE->value);
        $again = $this->scene->mapManager->prepareMap('synthetic/loading');
        expect($GLOBALS['g4ResourceCatalogLoads'])->toBe(2)
            ->and($again->worldObjects[0]->resource)->not->toBe($prepared->worldObjects[0]->resource);
        foreach ($sources as $path => $source) { expect(file_get_contents($path))->toBe($source); }
    } finally { unset($GLOBALS['g4ResourceCatalogLoads']); }
});

it('keeps joined decoration crowns out of graphical composition and replaces only disjoint trunk covers', function () {
    $catalog = createResourceWorldObjectCatalog($this->root);
    $entries = [
        ['id' => 'left', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1],
            'covers' => ['layer' => 'fixtures', 'cells' => [[1, 1]], 'tileLayers' => ['objects']]],
        ['id' => 'right', 'resource' => 'whole', 'anchor' => ['x' => 2, 'y' => 1],
            'covers' => ['layer' => 'fixtures', 'cells' => [[2, 1]], 'tileLayers' => ['objects']]],
    ];
    $base = createWorldObjectPreparedMap($entries, catalog: $catalog);
    $layers = new MapLayerSet([...$base->layers->layers, new MapLayer('canopy', 30, true, 'canopy', "(())\n(  )\n    ")]);
    $occupancy = array_fill(0, 3, array_fill(0, 4, CollisionType::NONE));
    $occupancy[1][1] = $occupancy[1][2] = CollisionType::SOLID;
    $collisions = MapCollisionResolver::resolveMap($layers, ['occupancy' => $occupancy])->collisionGrid;
    $prepared = new PreparedMap($base->data, $layers->getComposedGrid(), $collisions, [], [], [], $layers, $base->graphics,
        WorldObjectDefinition::readMap($base->data, $layers, $base->graphics, catalog: $catalog));
    $this->scene->mapManager->applyPreparedMap($prepared, $this->scene->player);
    $manager = $this->scene->mapManager;
    $before = [serialize($this->scene->createSnapshot()), $manager->getCollisionGrid(), $manager->tileMap,
        serialize($this->scene->eventManager->activeEvents)];
    Console::recomposeFrame(fn() => $manager->render());
    $terminal = Console::presentationSnapshot();
    $cells = getWorldObjectWireCells($manager->getPresentationWorld());
    expect(array_column($cells[0], 'glyph'))->toBe(['g', 'g', 'g', 'g'])
        ->and($cells[1][1]['glyph'])->toBe('g')->and($cells[1][2]['glyph'])->toBe('s')
        ->and($manager->canMoveTo(1, 1))->toBeFalse()->and($manager->canMoveTo(0, 0))->toBeTrue()
        ->and(fn() => WorldObjectDefinition::readMap(['worldObjects' => [$entries[0], array_replace($entries[0], ['id' => 'overlap'])]],
            $layers, $base->graphics, catalog: $catalog))->toThrow(InvalidArgumentException::class);
    $plain = new PreparedMap(array_replace($base->data, ['worldObjects' => []]), $prepared->tiles, $collisions,
        [], [], [], $layers, $base->graphics);
    $manager->applyPreparedMap($plain, $this->scene->player);
    Console::recomposeFrame(fn() => $manager->render());
    expect(Console::presentationSnapshot())->toEqual($terminal)
        ->and([serialize($this->scene->createSnapshot()), $manager->getCollisionGrid(), $manager->tileMap,
            serialize($this->scene->eventManager->activeEvents)])->toBe($before);
});

it('shares static frames only within each operation and recovers replaceable art without changing stable placement ids', function () {
    $catalog = createResourceWorldObjectCatalog($this->root, ['sprites2d' => ['asset' => 'Graphics/base.png',
        'cells' => ['width' => 3, 'height' => 4], 'sourceRect' => ['x' => 2, 'y' => 1, 'width' => 10, 'height' => 9]]]);
    $entries = [['id' => 'left', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1]],
        ['id' => 'right', 'resource' => 'whole', 'anchor' => ['x' => 2, 'y' => 1]]];
    $this->scene->mapManager->applyPreparedMap(createWorldObjectPreparedMap($entries, catalog: $catalog), $this->scene->player);
    $manager = $this->scene->mapManager;
    $collect = function () use ($manager): array {
        $frames = [];
        foreach ($manager->getWorldObjects() as $object) { $frames[] = $object->getGraphicalSpriteDefinition(); }
        return $frames;
    };
    $old = $manager->findWorldObject('left');
    $identity = $old->getGraphicalSpriteId();
    $frames = $collect();
    expect($frames[0])->toBe($frames[1])
        ->and(new ReflectionProperty(MapManager::class, 'worldObjectResourceFrames')->getValue($manager))->toBeNull();
    writeTestPng($this->root . '/assets/Graphics/base.png', 8, 6);
    $frames = $collect();
    expect($frames[0])->toBe($frames[1])->and($frames[0]->sourceRect->toArray())->toBe(['x' => 2, 'y' => 1, 'width' => 6, 'height' => 5]);
    unlink($this->root . '/assets/Graphics/base.png');
    expect($collect())->toBe([null, null])->and($old->hidesOwnedGlyphs())->toBeFalse()
        ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('World object resource whole');
    writeTestPng($this->root . '/assets/Graphics/base.png', 20, 12);
    expect($collect()[0]->sourceRect->width)->toBe(10)->and($old->getGraphicalSpriteId())->toBe($identity);
    $entries[0]['anchor'] = ['x' => 0, 'y' => 2];
    $manager->applyPreparedMap(createWorldObjectPreparedMap($entries, catalog: $catalog), $this->scene->player);
    $moved = $manager->findWorldObject('left');
    expect($moved->getGraphicalSpriteId())->toBe($identity)->and($moved->position)->toEqual(new Vector2(0, 2))
        ->and($old->isCurrent())->toBeFalse()->and($old->getGraphicalSpriteDefinition())->toBeNull()
        ->and(new ReflectionProperty(MapManager::class, 'worldObjectResourceRoles')->getValue($manager))->toBe([]);
    $collect();
    $manager->clearWorldObjects();
    expect($moved->getGraphicalSpriteDefinition())->toBeNull()
        ->and(new ReflectionProperty(MapManager::class, 'worldObjectResourceRoles')->getValue($manager))->toBe([]);
});

it('keeps animation clocks per resource placement and releases them on reinstall', function () {
    $catalog = createResourceWorldObjectCatalog($this->root, ['sprites2d' => ['asset' => 'Graphics/base.png',
        'animation' => ['columns' => 2, 'frames' => [0, 1], 'fps' => 10]]]);
    $entries = [['id' => 'left', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1]],
        ['id' => 'right', 'resource' => 'whole', 'anchor' => ['x' => 2, 'y' => 1]]];
    $prepared = createWorldObjectPreparedMap($entries, catalog: $catalog);
    $this->scene->mapManager->applyPreparedMap($prepared, $this->scene->player);
    $left = $this->scene->mapManager->findWorldObject('left');
    $right = $this->scene->mapManager->findWorldObject('right');
    $left->advanceGraphicalAnimation(.1);
    expect($left->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(6)
        ->and($right->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0)
        ->and($left->definition->sprites)->toBe($right->definition->sprites);
    $this->scene->mapManager->applyPreparedMap($prepared, $this->scene->player);
    $left->advanceGraphicalAnimation(100);
    expect($left->getGraphicalSpriteDefinition())->toBeNull()
        ->and($this->scene->mapManager->findWorldObject('left')->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
});

it('keeps ground row ordering stable across resource pivots and draw bands and retains off-grid overhang', function () {
    $catalog = createResourceWorldObjectCatalog($this->root);
    $entry = ['id' => 'placed', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1]];
    $this->scene->mapManager->applyPreparedMap(createWorldObjectPreparedMap([$entry], catalog: $catalog), $this->scene->player);
    $object = $this->scene->mapManager->findWorldObject('placed');
    $projected = new GraphicalSpriteProjector()->project($object, $this->scene->camera);
    $north = new PresentationSprite('north', 'Graphics/base.png', $projected->x, $projected->y - 1, 24, 24, layer: 100);
    $south = new PresentationSprite('south', 'Graphics/base.png', $projected->x, $projected->y + 1, 24, 24, layer: 100);
    $tie = new PresentationSprite('tie', 'Graphics/base.png', $projected->x, $projected->y, 24, 24, layer: 100);
    $above = new PresentationSprite('above', 'Graphics/base.png', $projected->x, $projected->y - 10, 24, 24, layer: 101);
    expect(array_column(PresentationSprite::orderedList([$south, $projected, $tie, $above, $north]), 'id'))
        ->toBe(['north', $object->getGraphicalSpriteId(), 'tie', 'south', 'above'])
        ->and([$projected->width, $projected->height])->toBe([3 * FieldViewport::TILE_SIZE, 4 * FieldViewport::TILE_SIZE])
        ->and($projected->pivot->toArray())->toBe(['x' => .25, 'y' => .75]);
    $viewport = new PresentationViewport(2, 0, 0, new CanvasRectangle(0, 0, 120, 120), spriteIds: [$projected->id]);
    expect($viewport->toArray()['scale'])->toBe(2.0)->and($projected->pivot)->toBe($catalog->getResource('whole')->pivot);
    $this->scene->camera->position = new Vector2(20, 20);
    $offGrid = new GraphicalSpriteProjector()->project($object, $this->scene->camera);
    expect($offGrid)->not->toBeNull()->and($offGrid->x)->toBeLessThan(0)->and($offGrid->y)->toBeLessThan(0);
});

it('clears a static frame pass after interrupted collection and releases shared roles on replacement', function () {
    $catalog = createResourceWorldObjectCatalog($this->root);
    $prepared = createWorldObjectPreparedMap([['id' => 'placed', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1]]], catalog: $catalog);
    $manager = $this->scene->mapManager;
    $manager->applyPreparedMap($prepared, $this->scene->player);
    try {
        foreach ($manager->getWorldObjects() as $object) {
            $object->getGraphicalSpriteDefinition();
            throw new RuntimeException('Synthetic interrupted projection');
        }
    } catch (RuntimeException) {}
    $roles = new ReflectionProperty(MapManager::class, 'worldObjectResourceRoles')->getValue($manager);
    expect($roles)->toHaveCount(1)
        ->and(new ReflectionProperty(MapManager::class, 'worldObjectResourceFrames')->getValue($manager))->toBeNull();
    $manager->applyPreparedMap($prepared, $this->scene->player);
    expect(array_values($roles)[0]->getFrame($catalog->getResource('whole')->pivot))->toBeNull();
});

it('measures bounded dense reused objects with mixed sprite transport unchanged frames and scroll deltas', function () {
    $width = 90;
    $height = 54;
    $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'ground', implode("\n", array_fill(0, $height, str_repeat('g', $width))))]);
    $sources = [];
    for ($i = 0; $i < 4; $i++) {
        writeTestPng($this->root . '/assets/Graphics/static-' . $i . '.png', 12 + $i, 20 + $i);
        $sources['static-' . $i] = ['name' => 'Synthetic ' . $i, 'pivot' => ['x' => .5, 'y' => .875],
            'sprites2d' => ['asset' => 'Graphics/static-' . $i . '.png', 'cells' => ['width' => 3, 'height' => 4], 'layer' => 100]];
    }
    $catalogStart = hrtime(true);
    $catalog = FieldPresentationCatalog::fromArray(['resources' => $sources], $this->root . '/assets');
    $catalogMs = (hrtime(true) - $catalogStart) / 1e6;
    $entries = $inlineEntries = [];
    for ($i = 0; $i < 128; $i++) {
        $anchor = ['x' => ($i * 3) % $width, 'y' => 1 + intdiv($i, 30) * 5];
        if ($i < 112) {
            $id = 'static-' . ($i % 4);
            $entries[] = ['id' => 'placed-' . $i, 'resource' => $id, 'anchor' => $anchor];
            $inlineEntries[] = ['id' => 'placed-' . $i, 'anchor' => $anchor,
                'pivot' => $sources[$id]['pivot'], 'sprites2d' => $sources[$id]['sprites2d']];
        } else {
            $entry = createWorldObjectSource(['id' => 'placed-' . $i, 'anchor' => $anchor,
                'sprites2d' => $i < 120 ? ['asset' => 'Graphics/glow.png', 'animation' => ['columns' => 2, 'frames' => [0, 1], 'fps' => 10]]
                    : ['asset' => 'Graphics/base.png']]);
            unset($entry['covers']);
            $entries[] = $inlineEntries[] = $entry;
        }
    }
    $measure = function (array $declarations) use ($catalog, $layers): array {
        $before = memory_get_usage();
        $start = hrtime(true);
        $definitions = WorldObjectDefinition::readMap(['worldObjects' => $declarations], $layers, catalog: $catalog);
        $parseMs = (hrtime(true) - $start) / 1e6;
        $definitionBytes = memory_get_usage() - $before;
        $data = ['id' => 'synthetic/room', 'worldObjects' => $declarations];
        $prepared = new PreparedMap($data, $layers->getComposedGrid(), array_fill(0, 54, array_fill(0, 90, CollisionType::NONE->value)),
            [], [], [], $layers, worldObjects: $definitions);
        $this->scene->mapManager->applyPreparedMap($prepared, $this->scene->player);
        $samples = [];
        $projector = new GraphicalSpriteProjector();
        for ($pass = 0; $pass < 20; $pass++) {
            $sprites = [];
            $start = hrtime(true);
            foreach ($this->scene->mapManager->getWorldObjects() as $object) {
                $sprites[] = $projector->project($object, $this->scene->camera);
            }
            $samples[] = (hrtime(true) - $start) / 1e6;
        }
        sort($samples);
        $roles = new ReflectionProperty(MapManager::class, 'worldObjectResourceRoles')->getValue($this->scene->mapManager);
        return ['parse_ms' => $parseMs, 'definition_bytes' => $definitionBytes, 'projection_median_ms' => ($samples[9] + $samples[10]) / 2,
            'projection_max_ms' => max($samples), 'shared_static_roles' => count($roles), 'sprites' => $sprites];
    };
    $inline = $measure($inlineEntries);
    $reused = $measure($entries);
    expect(array_map(fn($sprite) => $sprite->toArray(pivot: true), $reused['sprites']))
        ->toBe(array_map(fn($sprite) => $sprite->toArray(pivot: true), $inline['sprites']))
        ->and($reused['shared_static_roles'])->toBe(4)->and($inline['shared_static_roles'])->toBe(0);
    $sprites = $reused['sprites'];
    // Representative player/NPC/staged/effect payloads; no native animation or paint is timed.
    foreach (['player' => 1, 'npc' => 32, 'staged' => 16, 'effect' => 16] as $kind => $count) {
        for ($i = 0; $i < $count; $i++) {
            $sprites[] = new PresentationSprite($kind . ':' . $i, 'Graphics/base.png', $i, $i % 12, 24, 24,
                layer: $kind === 'effect' ? 200 : 100);
        }
    }
    $transport = new FakeRendererTransport();
    $client = new RendererClient($transport);
    $grid = new RendererGridConfig(12, 6);
    $client->start(new RendererSessionConfig('Synthetic resource measurement', $this->root . '/assets', $grid,
        RendererProtocolVersion::V2, [RendererSessionConfig::SPRITE_PIVOT, RendererSessionConfig::SPRITE_SOURCE_RECT]));
    $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_pivot","sprite_source_rect"]}')];
    $client->pump();
    $presentation = new RendererPresentation($client, $grid);
    $world = $this->scene->mapManager->getPresentationWorld();
    $viewport = fn(int $x) => new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, 120, 120),
        spriteIds: array_column($sprites, 'id'), worldId: 'map', worldOriginX: $x);
    $start = hrtime(true);
    $presentation->present(new ConsolePresentationChanges(12, 6, true, order: []), $sprites, viewport: $viewport(0), world: $world);
    for ($tick = 0; $tick < 100 && $presentation->hasPendingUpload(); $tick++) {
        $last = end($transport->sent)->payload;
        $presentation->acknowledge($last['generation'], $last['present']);
        $presentation->present(new ConsolePresentationChanges(12, 6, false), $sprites, viewport: $viewport(0), world: $world);
    }
    $initialMs = (hrtime(true) - $start) / 1e6;
    expect($presentation->hasPendingUpload())->toBeFalse();
    $last = end($transport->sent)->payload;
    $presentation->acknowledge($last['generation'], $last['present']);
    $initialPackets = count($transport->sent);
    $initialBytes = array_sum(array_map(fn($message) => strlen($message->encode()), $transport->sent));
    $transport->sent = [];
    expect($presentation->present(new ConsolePresentationChanges(12, 6, false), $sprites, viewport: $viewport(0), world: $world))->toBeFalse()
        ->and($transport->sent)->toBe([]);
    $scrollBytes = [];
    $scrollPackets = [];
    $scrollProjectionMs = [];
    $mixedBase = array_slice($sprites, 128);
    for ($x = 1; $x <= 8; $x++) {
        $beforePackets = count($transport->sent);
        $this->scene->camera->position = new Vector2($x, 0);
        $scrolled = [];
        $start = hrtime(true);
        foreach ($this->scene->mapManager->getWorldObjects() as $object) {
            $scrolled[] = new GraphicalSpriteProjector()->project($object, $this->scene->camera);
        }
        $scrollProjectionMs[] = (hrtime(true) - $start) / 1e6;
        foreach ($mixedBase as $sprite) {
            $scrolled[] = str_starts_with($sprite->id, 'effect:') ? $sprite : new PresentationSprite($sprite->id,
                $sprite->asset, $sprite->x - $x, $sprite->y, $sprite->width, $sprite->height, layer: $sprite->layer);
        }
        $presentation->present(new ConsolePresentationChanges(12, 6, false), $scrolled, viewport: $viewport($x), world: $world);
        for ($tick = 0; $tick < 100 && $presentation->hasPendingUpload(); $tick++) {
            $last = end($transport->sent)->payload;
            $presentation->acknowledge($last['generation'], $last['present']);
            $presentation->present(new ConsolePresentationChanges(12, 6, false), $scrolled, viewport: $viewport($x), world: $world);
        }
        expect($presentation->hasPendingUpload())->toBeFalse();
        $last = end($transport->sent);
        $packets = array_slice($transport->sent, $beforePackets);
        $operations = array_merge(...array_map(fn($message) => $message->payload['operations'], $packets));
        expect(array_column($operations, 'kind'))->each->toBe('sprite')
            ->and($operations)->toHaveCount(177)
            ->and($last->payload['viewport']['worldOrigin']['column'])->toBe($x);
        $scrollBytes[] = array_sum(array_map(fn($message) => strlen($message->encode()), $packets));
        $scrollPackets[] = count($packets);
        $presentation->acknowledge($last->payload['generation'], $last->payload['present']);
    }
    expect($sprites)->toHaveCount(193)->and($scrollPackets)->toHaveCount(8);
    $budget = $sprites;
    for ($i = count($budget); $i < 1024; $i++) { $budget[] = new PresentationSprite('budget:' . $i, 'Graphics/base.png', 0, 0, 24, 24); }
    expect(PresentationSprite::orderedList($budget))->toHaveCount(1024);
    $budget[] = new PresentationSprite('overflow', 'Graphics/base.png', 0, 0, 24, 24);
    expect(fn() => PresentationSprite::orderedList($budget))->toThrow(InvalidArgumentException::class);
    unset($inline['sprites'], $reused['sprites']);
    $receipt = ['php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY, 'world_cells' => $width * $height, 'object_count' => 128,
        'reused_static_placements' => 112, 'animated_inline' => 8, 'conditional_inline' => 8, 'mixed_sprites' => count($sprites),
        'passes' => 20, 'catalog_ms' => $catalogMs, 'inline' => $inline, 'reused' => $reused,
        'initial_transport_ms' => $initialMs, 'initial_packets' => $initialPackets, 'initial_encoded_bytes' => $initialBytes,
        'unchanged_packets' => 0, 'scroll_encoded_bytes' => $scrollBytes, 'scroll_projection_ms' => $scrollProjectionMs,
        'scroll_packets' => $scrollPackets, 'scroll_sprite_updates' => 177, 'scroll_world_uploads' => 0,
        'php_peak_bytes' => memory_get_peak_usage(),
        'limitations' => 'PHP projection and fake retained transport only; static probes inferred from four shared role/frame calls per pass, not OS-counted; no decode, native paint, FPS, real art or NPC AI. Mixed actor/effect payloads are representative synthetic values, not those managers.'];
    if (($path = getenv('ICHILOTO_RESOURCE_RECEIPT')) !== false) {
        file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    $client->shutdown();
});
