<?php

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\RetainedWorldProviderInterface;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class RetainedMapManagerProbe extends MapManager
{
    public function __construct(GameScene $scene) { $this->gameScene = $scene; }
    public function readSource(array $paths): array { return $this->readSplitMapDataFromFiles($paths); }
    public function unloadGeometry(): void { $this->clearMapGeometry(); }
    protected function applyMapBackgroundMusic(mixed $bgm, mixed $variants = []): void {}
}

/** Counts real map calls while refusing per-cell map projection during a retained scroll. */
final class RetainedScrollCameraProbe extends Camera
{
    public int $mapDraws = 0;
    public int $projections = 0;

    public function renderMap(): void
    {
        $this->mapDraws++;
        parent::renderMap();
    }

    public function visibleMapRows(): iterable
    {
        throw new LogicException('A retained scroll must not collect visible map tiles.');
    }

    public function getScreenSpacePosition(Vector2 $worldSpacePosition): Vector2
    {
        $this->projections++;
        return parent::getScreenSpacePosition($worldSpacePosition);
    }
}

beforeEach(function () {
    $this->runtime = null;
    $this->states = [];
    foreach ([Console::class, TerminalCapabilities::class, Debug::class, ConfigStore::class, InputManager::class] as $class) {
        $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['sprites' => ['allow_composite_emoji' => true]]]));
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
        new ReflectionProperty(Console::class, $key)->setValue(null, $value);
    }
    Console::setTerminalOutputEnabled(false);
    Console::setLayerTracking(true);
    Console::setRetainedWorldPresentation(true);
    Console::syncDimensions(8, 4);
    $this->root = sys_get_temp_dir() . '/ichiloto-retained-map-' . bin2hex(random_bytes(8));
    mkdir($this->root);
    Debug::configure(['log_directory' => $this->root]);
    $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $this->camera = new Camera(makeCameraTestScene(), 8, 4);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
    $this->manager = new RetainedMapManagerProbe($this->scene);
    new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $this->manager);
    $this->records = [];
    LatencyTrace::configure(function (array $record): void { $this->records[] = $record; });
});

afterEach(function () {
    $this->runtime?->shutdown();
    LatencyTrace::configure();
    foreach ($this->states as $class => $state) {
        foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($this->root);
});

function createRetainedMapSource(string $root, string $name, string $text = '....', bool $legacy = false): array
{
    $directory = $root . '/' . $name;
    mkdir($directory);
    $paths = ['id' => $name, 'data' => $directory . '/' . $name . '.data.php',
        'event' => $directory . '/' . $name . '.event.php', 'map' => $directory . '/' . $name . '.map.php'];
    file_put_contents($paths['data'], '<?php return [];');
    file_put_contents($paths['event'], MapGridSource::buildSource(str_repeat(' ', count(MapLayer::parseGrid($text)[0])), 'EVENT'));
    if (!$legacy) { mkdir($directory . '/layers'); }
    file_put_contents($legacy ? $paths['map'] : $directory . '/layers/00.terrain.map.php', MapGridSource::buildSource($text, 'MAP'));
    return $paths;
}

function activateRetainedField(GameScene $scene, FieldViewport $viewport): void
{
    $player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
    new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
    $field = new ReflectionClass(FieldState::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
    new ReflectionProperty(GameScene::class, 'fieldViewport')->setValue($scene, $viewport);
}

it('caches world uploads per successful installed map and width policy, preserving the active world on failed load', function (bool $legacy) {
    $paths = createRetainedMapSource($this->root, 'first', legacy: $legacy);
    $this->manager->readSource($paths);
    $world = $this->manager->getPresentationWorld();
    // The world declares its field cell size, so the renderer draws every terminal cell as a
    // 24 x 48 box: RPG Maker's 48-pixel tile covers two cells across.
    expect($world?->id)->toBe('map')
        ->and(array_keys($world->operations[0]['value']))->toBe(['columns', 'rows', 'cellWidth', 'cellHeight', 'layers'])
        ->and($world->operations[0]['value']['columns'])->toBe(4)
        ->and([$world->operations[0]['value']['cellWidth'], $world->operations[0]['value']['cellHeight']])->toBe([24, 48])
        ->and($world->operations[0]['value']['layers'][0])->not->toHaveKeys(['asset', 'sources']);
    $this->records = [];
    for ($index = 0; $index < 20; $index++) {
        expect($this->manager->getPresentationWorld())->toBe($world);
        $this->manager->render();
    }
    expect($this->records)->toBe([]);
    $bad = createRetainedMapSource($this->root, 'bad');
    file_put_contents($bad['event'], MapGridSource::buildSource(' ', 'EVENT'));
    expect(fn() => $this->manager->readSource($bad))->toThrow(InvalidArgumentException::class)
        ->and($this->manager->getPresentationWorld())->toBe($world);
    $this->manager->readSource(createRetainedMapSource($this->root, 'second', 'xx'));
    expect($this->manager->getPresentationWorld())->not->toBe($world);
    $second = $this->manager->getPresentationWorld();
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['sprites' => ['allow_composite_emoji' => false]]]));
    expect(TerminalCapabilities::supportsCompositeEmoji())->toBeFalse()
        ->and($this->manager->getPresentationWorld())->not->toBe($second);
    $changed = $this->manager->getPresentationWorld();
    expect($this->manager->getPresentationWorld())->toBe($changed);
    $this->manager->unloadGeometry();
    expect($this->manager->getPresentationWorld())->toBeNull()->and($this->camera->worldSpace)->toBe([]);
})->with([false, true]);

it('replaces the cached world when a prepared destination is committed', function () {
    $this->manager->readSource(createRetainedMapSource($this->root, 'first'));
    $before = $this->manager->getPresentationWorld();
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'second', 'xy')]);
    $party = new ReflectionClass(Party::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, $party);
    $player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Player::class, 'position')->setValue($player, new \Ichiloto\Engine\Core\Vector2(0, 0));
    $prepared = new PreparedMap([], $layers->getComposedGrid(), [[0, 0]], [], [], layers: $layers);
    $this->manager->applyPreparedMap($prepared, $player);
    expect($this->manager->getPresentationWorld())->not->toBe($before)
        ->and($this->camera->worldSpace)->toBe([['x', 'y']]);
});

it('keeps useful screen-space retained glyphs when the world budget refuses a map', function () {
    $layers = [];
    for ($index = 0; $index < 65; $index++) { $layers[] = new MapLayer('layer' . $index, $index, false, 'test', $index === 0 ? 'base' : '    '); }
    $set = new MapLayerSet($layers);
    new ReflectionProperty(MapManager::class, 'layers')->setValue($this->manager, $set);
    $this->camera->worldSpace = $set->getComposedGrid();
    expect($this->manager->getPresentationWorld())->toBeNull();
    $this->manager->render();
    expect(implode('', Console::snapshot()->rows))->toContain('base')
        ->and(Console::getRetainedPresentationChanges()->layers)->not->toBe([]);
    $warning = file_get_contents($this->root . '/warning.log');
    $this->manager->getPresentationWorld();
    expect(file_get_contents($this->root . '/warning.log'))->toBe($warning);
});

it('erases complete actor glyphs without map spaces while preserving UI and terminal fallback', function () {
    $this->manager->readSource(createRetainedMapSource($this->root, 'first'));
    $this->manager->getPresentationWorld();
    Console::withLayer('npc:a', fn() => Console::write('界', 2, 1), 10);
    Console::withLayer('ui:dialogue', fn() => Console::write('U', 3, 1), 1000);
    Console::getRetainedPresentationChanges();
    $this->manager->renderBackgroundTile(1, 0);
    $changes = Console::getRetainedPresentationChanges();
    expect($changes->removedIds)->toContain('npc:a')
        ->and(Console::charAt(3, 1))->toBe('U')
        ->and(array_column($changes->layers, 'id'))->not->toContain('map:terrain', 'world');
    Console::setRetainedWorldPresentation(false);
    $this->manager->render();
    expect(implode('', Console::snapshot()->rows))->toContain('....');
    // The flag alone never suppresses an unrelated standalone camera's map.
    Console::setRetainedWorldPresentation(true);
    $this->camera->worldSpace = [['n', 'e', 'w']];
    $this->camera->renderMap();
    expect(implode('', Console::snapshot()->rows))->toContain('new');
});

it('keeps T1 drawing active even when a valid retained world and request flag exist', function () {
    $this->manager->readSource(createRetainedMapSource($this->root, 'first'));
    $this->manager->getPresentationWorld();
    Console::setTerminalOutputEnabled(true);
    ob_start();
    try { $this->manager->render(); }
    finally { ob_end_clean(); }
    expect(implode('', Console::snapshot()->rows))->toContain('....');
});

it('reports signed logical origins and keeps scale-one world viewports through unchanged deltas and menu return', function () {
    $this->manager->readSource(createRetainedMapSource($this->root, 'first'));
    activateRetainedField($this->scene, new FieldViewport(new RendererGridConfig(8, 4, 10, 20)));
    expect($this->scene)->toBeInstanceOf(RetainedWorldProviderInterface::class)
        ->and($this->camera->getWorldOrigin())->toBe(['x' => -2, 'y' => -1]);
    $world = $this->scene->getPresentationWorld();
    $first = new ConsolePresentationChanges(8, 4, true, [
        ['id' => 'npc:text', 'layer' => 10, 'rows' => []], ['id' => 'dialogue', 'layer' => 1000, 'rows' => []],
    ]);
    $viewport = $this->scene->getPresentationViewport($first, []);
    expect($viewport?->scale)->toBe(1.0)->and($viewport->worldId)->toBe('map')
        ->and($viewport->toArray()['worldOrigin'])->toBe(['column' => -2, 'row' => -1])
        ->and($viewport->textLayerIds)->toBe(['npc:text'])
        ->and($viewport->toArray())->not->toHaveKey('tileBatchIds');
    $unchanged = new ConsolePresentationChanges(8, 4, false);
    expect($this->scene->getPresentationViewport($unchanged, [])?->toArray())->toBe($viewport->toArray());
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, new ReflectionClass(MainMenuState::class)->newInstanceWithoutConstructor());
    expect($this->scene->getPresentationWorld())->toBeNull()
        ->and($this->scene->getPresentationViewport($unchanged, []))->toBeNull();
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $this->scene->fieldState);
    expect($this->scene->getPresentationWorld())->toBe($world);
    $removed = new ConsolePresentationChanges(8, 4, false, removedIds: ['npc:text']);
    expect($this->scene->getPresentationViewport($removed, [])?->textLayerIds)->toBe([]);
    $this->camera->worldSpace = array_fill(0, 8, array_fill(0, 20, '.'));
    $this->camera->moveTo(3, 2);
    expect($this->camera->getWorldOrigin())->toBe(['x' => 3, 'y' => 2]);
});

it('preserves snapshot tools and separates world origin from screen-projected sprite scaling', function () {
    Console::withLayer('npc:text', fn() => Console::write('N', 1, 1), 10);
    $layout = new FieldViewport(new RendererGridConfig(8, 4, 10, 20), 2);
    $sprite = new PresentationSprite('npc:sprite', 'npc.png', 2, 1, 16, 24);
    $snapshot = Console::presentationSnapshot();
    $viewport = $layout->createViewport($snapshot, [$sprite], worldId: 'map', worldOrigin: ['x' => -2, 'y' => 10]);
    $viewport->assertMembers($snapshot, [$sprite]);
    // 80 pixels across hold one 48-pixel cell at 2x; the other 32 are split evenly.
    expect($viewport->x)->toBe(16.0)->and($viewport->spriteIds)->toBe(['npc:sprite'])
        ->and($sprite->x)->toBe(2)->and($sprite->y)->toBe(1)
        ->and($viewport->toArray()['worldOrigin'])->toBe(['column' => -2, 'row' => 10]);
    $changes = Console::getRetainedPresentationChanges();
    $layout->createViewport($changes, [$sprite])->assertMembers($changes, [$sprite]);
    expect(fn() => new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, 80, 80), worldOriginX: -1))
        ->toThrow(InvalidArgumentException::class);
});

it('scrolls through the real field compositor and runtime without revisiting static cells at any window or layer count', function (
    int $columns, int $rows, int $layerCount, int $mapWidth, int $mapHeight
) {
    // Release prior PHPUnit scene/runtime cycles before constructing the next large map.
    gc_collect_cycles();
    Console::syncDimensions($columns, $rows);
    $transport = new FakeRendererTransport();
    $runtime = $this->runtime = new RendererRuntime(new RendererRuntimeConfig(
        new RendererProcessConfig(['fixture']), $this->root, cellWidth: 10, cellHeight: 20), $transport);
    $runtime->start('Retained scroll guard', $columns, $rows);
    $game = $this->getMockBuilder(Game::class)->disableOriginalConstructor()
        ->onlyMethods(['getRendererRuntime', '__destruct'])->getMock();
    $game->method('getRendererRuntime')->willReturn($runtime);
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
        ->onlyMethods(['getGame'])->getMock();
    $scene->method('getGame')->willReturn($game);
    new ReflectionProperty(AbstractScene::class, 'sceneManager')->setValue($scene,
        new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor());
    // The graphical field shows whole 48-pixel cells, not the text grid; size the camera as the field does.
    $layout = new FieldViewport($runtime->grid);
    $camera = new RetainedScrollCameraProbe($scene, $layout->columns, $layout->rows);
    expect($camera->screen->getWidth())->toBe($layout->columns);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    $manager = new RetainedMapManagerProbe($scene);
    new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $manager);
    new ReflectionProperty(GameScene::class, 'party')->setValue($scene,
        new ReflectionClass(Party::class)->newInstanceWithoutConstructor());
    // Isolate static-map work; real FieldState still calls the actor/UI producers.
    $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
        ->onlyMethods(['render', 'renderEventCues', 'getGraphicalSpriteDefinition'])->getMock();
    $player->method('getGraphicalSpriteDefinition')->willReturn(null);
    $player->expects($this->exactly(6))->method('render');
    $player->expects($this->exactly(6))->method('renderEventCues');
    new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
    new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(
        10 + $camera->getHorizontalFocusPosition(), 10 + $camera->getVerticalFocusPosition()));
    $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()->onlyMethods(['render'])->getMock();
    $ui->expects($this->exactly(6))->method('render');
    new ReflectionProperty(AbstractScene::class, 'uiManager')->setValue($scene, $ui);
    $field = new FieldState(new SceneStateContext($scene));
    new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);

    $layers = [];
    for ($index = 0; $index < $layerCount; $index++) {
        $name = 'layer' . $index;
        $marker = $index === 0 ? '.' : chr(ord('A') + $index);
        $row = $index === 0 ? str_repeat('.', $mapWidth)
            : substr_replace(str_repeat(' ', $mapWidth), $marker, $index, 1);
        $layers[] = new MapLayer($name, $index, $index % 2 === 1, $name,
            implode("\n", array_fill(0, $mapHeight, $row)));
    }
    $set = new MapLayerSet($layers);
    $manager->applyPreparedMap(new PreparedMap([], $set->getComposedGrid(),
        array_fill(0, $mapHeight, array_fill(0, $mapWidth, 0)), [], [], layers: $set), $player);
    new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
    $field->renderTheField();
    expect($runtime->present($scene))->toBeTrue();
    $world = $manager->getPresentationWorld();
    expect($world)->not->toBeNull()->and($world->operations[0]['value']['layers'])->toHaveCount($layerCount)
        ->and($world->operations[0]['value']['columns'])->toBe($mapWidth)
        ->and(array_column($world->operations, 'op'))->toContain('worldRows')->not->toContain('worldTiles');
    $acknowledge = static function (array $packet) use ($transport, $runtime): void {
        $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $packet['generation'], 'frame' => $packet['frame'],
            'presented' => $packet['present']], JSON_THROW_ON_ERROR))];
        $runtime->pump();
    };
    // Map upload is deliberately staged across ticks; finish/ack it before measuring scroll work.
    $complete = false;
    for ($tick = 0; $tick <= count($world->operations); $tick++) {
        $last = end($transport->sent)->payload;
        $acknowledge($last);
        if ($last['present']) { $complete = true; break; }
        expect($runtime->present($scene))->toBeTrue();
    }
    expect($complete)->toBeTrue();
    $transport->sent = [];

    $buffer = new ReflectionProperty(Console::class, 'buffer');
    expect($buffer->getValue())->toBe([]);
    // A tripwire in an untouched row makes either full-snapshot API fail.
    // Real delta collection must not inspect rows without mutations.
    $buffer->setValue(null, [$rows - 1 => []]);
    try {
        expect(fn() => Console::snapshot())->toThrow(RuntimeException::class, 'must contain valid UTF-8 text')
            ->and(fn() => Console::presentationSnapshot())->toThrow(RuntimeException::class, 'must contain valid UTF-8 text');
    } finally { $buffer->setValue(null, []); }
    $tracker = new ReflectionProperty(Console::class, 'retainedPresentation');
    $composedRows = new ReflectionProperty($tracker->getValue(), 'composedRows');
    expect($composedRows->getValue($tracker->getValue()))->toBe(0);
    $this->records = [];
    $expectedX = $expectedY = 10;
    foreach ([Vector2::right(), Vector2::down(), Vector2::left(), Vector2::up()] as $direction) {
        $player->position->add($direction);
        expect($manager->scrollMap($player, $direction))->toBeTrue()
            ->and($scene->recomposeFieldAfterCameraScroll())->toBeTrue();
        $expectedX += (int)$direction->x;
        $expectedY += (int)$direction->y;
        expect($camera->getWorldOrigin())->toBe(['x' => $expectedX, 'y' => $expectedY])
            ->and($buffer->getValue())->toBe([]);
        $buffer->setValue(null, [$rows - 1 => []]);
        try { expect($runtime->present($scene))->toBeTrue(); }
        finally { $buffer->setValue(null, []); }
        $packet = end($transport->sent)->payload;
        expect($packet['operations'])->toBe([])->and($packet['reset'])->toBeFalse()
            ->and($packet['viewport']['worldOrigin'])->toBe(['column' => $expectedX, 'row' => $expectedY])
            ->and($packet['viewport']['clipRect'])->toBe(['x' => 0.0, 'y' => 0.0,
                'width' => (float)($columns * 10), 'height' => (float)($rows * 20)])
            ->and($manager->getPresentationWorld())->toBe($world)
            ->and($composedRows->getValue($tracker->getValue()))->toBe(0)
            ->and(new ReflectionProperty(Camera::class, 'normalizedRows')->getValue($camera))->toBe([]);
        // Only bounded viewport/envelope numbers may vary, never cells or layer data.
        $shape = $packet;
        unset($shape['generation'], $shape['baseGeneration'], $shape['frame']);
        $shape['viewport']['clipRect']['width'] = 2000.0;
        $shape['viewport']['clipRect']['height'] = 1000.0;
        $shape['viewport']['worldOrigin'] = ['column' => 10, 'row' => 10];
        // The field is centred in the session: half the pixels left over after whole field cells.
        $origin = ['x' => ($columns * 10 - $layout->columns * FieldViewport::CELL_WIDTH) / 2.0,
            'y' => ($rows * 20 - $layout->rows * FieldViewport::CELL_HEIGHT) / 2.0];
        expect($shape)->toBe(['reset' => false, 'present' => true, 'operations' => [],
            'viewport' => ['scale' => 1.0, 'origin' => $origin,
                'clipRect' => ['x' => 0.0, 'y' => 0.0, 'width' => 2000.0, 'height' => 1000.0],
                'textLayerIds' => ['world'], 'spriteIds' => [], 'worldId' => 'map',
                'worldOrigin' => ['column' => 10, 'row' => 10]]]);
        $acknowledge($packet);
    }
    expect($scene->recomposeFieldAfterCameraScroll())->toBeTrue()
        ->and($runtime->present($scene))->toBeFalse()->and($transport->sent)->toHaveCount(4)
        ->and($camera->mapDraws)->toBe(6)->and($camera->projections)->toBe(0)
        ->and(array_column($this->records, 'stage'))->not->toContain(
            'terminal.select', 'terminal.normalize', 'terminal.tokenize', 'terminal.format',
            'terminal.compose', 'terminal.diff', 'terminal.serialize', 'console.cells', 'console.runs');
    foreach (['buffer', 'layerCells', 'presentationBaseCells'] as $property) {
        expect(new ReflectionProperty(Console::class, $property)->getValue())->toBe([]);
    }
    $runtime->shutdown();
    $manager->unloadGeometry();
    $this->runtime = null;
})->with(function () {
    $cases = [];
    foreach ([[130, 30], [200, 50]] as [$columns, $rows]) {
        foreach ([1, 3, 12] as $layers) {
            foreach ([[221, 81], [301, 91]] as [$width, $height]) {
                $cases["{$columns}x{$rows}, {$layers} layers, {$width}x{$height} map"] = [$columns, $rows, $layers, $width, $height];
            }
        }
    }
    return $cases;
});
