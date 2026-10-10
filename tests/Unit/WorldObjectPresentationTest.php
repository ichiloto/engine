<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
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
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
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

function createWorldObjectPreparedMap(array $objects, string $id = 'synthetic/room'): PreparedMap
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
        [], [], [], $layers, $graphics, WorldObjectDefinition::readMap($data, $layers, $graphics));
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
