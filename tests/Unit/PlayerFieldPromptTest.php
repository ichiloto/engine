<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\graphicalSpriteData;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

final class PlayerPromptMapManager extends MapManager
{
    public ?PreparedMap $destination = null;
    public function __construct(GameScene $scene) { $this->gameScene = $scene; }
    public function prepareMap(string $mapFilename): PreparedMap
    {
        return $this->destination ?? throw new RuntimeException('Missing prompt-test destination.');
    }
    protected function applyMapBackgroundMusic(mixed $bgm, mixed $variants = []): void {}
}

final class PlayerPromptScene extends GameScene
{
    public function __construct(private Game $testGame) {}
    public function getGame(): Game { return $this->testGame; }
    protected function finalizePlayerTransfer(): void {}
}

final class PlayerPromptGame extends Game
{
    public function __construct(private RendererRuntime $testRuntime) {}
    // PHPUnit can release a mock's configured destructor before scene cycles are collected.
    public function __destruct() {}
    public function getRendererRuntime(): ?RendererRuntime { return $this->testRuntime; }
}

function createPlayerPromptMap(string $glyph): PreparedMap
{
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'prompt-map',
        implode("\n", array_fill(0, 45, str_repeat($glyph, 80))))]);
    return new PreparedMap([], $layers->getComposedGrid(), array_fill(0, 45, array_fill(0, 80, 0)),
        null, [], [], layers: $layers);
}

function presentPlayerPromptFrame(RendererRuntime $runtime, GameScene $scene, FakeRendererTransport $transport): array
{
    for ($tick = 0; $tick < 200; $tick++) {
        expect($runtime->present($scene))->toBeTrue();
        $packet = end($transport->sent)->payload;
        $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $packet['generation'], 'frame' => $packet['frame'],
            'presented' => $packet['present']], JSON_THROW_ON_ERROR))];
        $runtime->pump();
        if ($packet['present']) {
            $frames = RetainedFrameState::replay($transport->sent);
            return end($frames);
        }
    }
    throw new RuntimeException('Prompt test did not finish its bounded world upload.');
}

function getPlayerPromptRuns(array $frame): array
{
    $text = array_column($frame['textLayers'], null, 'id');
    return array_map(static fn(array $run): array => array_intersect_key($run,
        array_flip(['column', 'row', 'text'])), $text[PresentationLayerPolicy::FIELD_PROMPT_ID]['runs'] ?? []);
}

beforeEach(function () {
    gc_collect_cycles();
    $this->states = [];
    foreach ([Console::class, ConfigStore::class, InputManager::class, EventManager::class, Debug::class] as $class) {
        $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
        new ReflectionProperty(Console::class, $key)->setValue(null, $value);
    }
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(80, 40);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['field' => ['zoom' => 2.0]],
        'ui' => ['hud' => ['location' => false]]]));
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 40]));
    $this->root = sys_get_temp_dir() . '/ichiloto-player-prompt-' . bin2hex(random_bytes(8));
    mkdir($this->root);
    Debug::configure(['log_directory' => $this->root]);
    $this->transport = new FakeRendererTransport();
    $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']),
        $this->root, cellWidth: 10, cellHeight: 20), $this->transport);
    $this->runtime->start('Player prompt', 80, 40);
    $game = new PlayerPromptGame($this->runtime);
    $this->scene = new PlayerPromptScene($game);
    new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($this->scene, makeBareScene(SceneManager::class));
    new ReflectionProperty(GameScene::class, 'gameState')->setValue($this->scene, new GameState());
    $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()->onlyMethods(['render'])->getMock();
    $ui->method('render')->willReturnCallback(static function (): void {
        Console::withLayer('location-hud', fn() => Console::write('HUD', 75, 38), 1010);
    });
    $ui->locationHUDWindow = $this->getMockBuilder(LocationHUDWindow::class)->disableOriginalConstructor()
        ->onlyMethods(['updateDetails'])->getMock();
    new ReflectionProperty(GameScene::class, 'uiManager')->setValue($this->scene, $ui);
    $this->camera = new Camera($this->scene, 80, 40);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
    new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, makeBareScene(Party::class));
    $data = graphicalSpriteData();
    foreach ($data as &$definition) { $definition['height'] = 45; }
    unset($definition);
    $data['north']['height'] = 74;
    $this->player = new Player($this->scene, 'Prompt hero', new Vector2(6, 8), new Rect(0, 0, 1, 1), ['v'],
        MovementHeading::SOUTH, graphicalSprites: DirectionalGraphicalSpriteSet::fromArray($data));
    $this->manager = new PlayerPromptMapManager($this->scene);
    new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $this->manager);
    $this->manager->applyPreparedMap(createPlayerPromptMap('.'), $this->player);
    new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $this->player);
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
    $this->field = makeBareScene(FieldState::class);
    new ReflectionProperty(FieldState::class, 'context')->setValue($this->field, new SceneStateContext($this->scene));
    new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $this->field);
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $this->field);
    $this->scene->synchronizeFieldViewport();
    $this->player->availableAction = $this->createStub(ActionInterface::class);
});

afterEach(function () {
    $this->runtime->shutdown();
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    unset($this->runtime, $this->transport, $this->field, $this->scene, $this->camera, $this->player, $this->manager);
    gc_collect_cycles();
    foreach ($this->states as $class => $state) {
        foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
    foreach (new DirectoryIterator($this->root) as $file) {
        if (!$file->isDot()) { unlink($file->getPathname()); }
    }
    rmdir($this->root);
});

it('keeps exactly one above-art prompt aligned with the player through real 2x movement and scrolling', function () {
    $this->field->renderTheField();
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    foreach ([null, Vector2::right(), Vector2::right(), Vector2::up()] as $direction) {
        if ($direction !== null) {
            expect($this->player->tryMove($direction, $this->camera))->toBeTrue();
            $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
        }
        $sprite = $frame['sprites'][0];
        $row = $sprite['y'] - (int)ceil($sprite['height'] / 20);
        expect(getPlayerPromptRuns($frame))->toBe([['row' => $row, 'column' => $sprite['x'], 'text' => '!']])
            ->and($frame['viewport']['scale'])->toBe(2.0)
            ->and($frame['viewport']['textLayerIds'])->toContain(PresentationLayerPolicy::FIELD_PROMPT_ID)
            ->not->toContain('location-hud')
            ->and($frame['viewport']['spriteIds'])->toBe(['player'])
            ->and(array_column($frame['textLayers'], 'id'))->not->toContain('player');
        $text = array_column($frame['textLayers'], null, 'id');
        expect($text['field-prompt']['layer'])->toBe(1010)->toBeGreaterThan($sprite['layer'])
            ->and($text['location-hud']['runs'][0]['row'])->toBe(38);
        $scale = $frame['viewport']['scale'];
        // Both anchors use the same transform; the prompt's bottom is above the artwork's top.
        expect(($row + 1) * 20 * $scale)->toBeLessThanOrEqual((($sprite['y'] + 1) * 20 - $sprite['height']) * $scale);
    }
    $this->player->position->x = 50;
    $this->player->position->y = 25;
    $this->camera->moveTo(30, 15);
    $this->field->renderTheField();
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $before = $this->camera->getWorldOrigin();
    expect($this->player->tryMove(Vector2::right(), $this->camera))->toBeTrue()
        ->and($this->camera->getWorldOrigin())->not->toBe($before);
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $sprite = $frame['sprites'][0];
    expect(getPlayerPromptRuns($frame))->toBe([['row' => $sprite['y'] - 3, 'column' => $sprite['x'], 'text' => '!']]);
});

it('replaces old prompt rows through either player draw entry point and removes them as soon as canAct becomes false', function (string $draw) {
    $this->field->renderTheField();
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $this->player->position->x += 2;
    $this->player->position->y += 1;
    $this->player->$draw();
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(getPlayerPromptRuns($frame))->toBe([['row' => 6, 'column' => 8, 'text' => '!']])
        ->and(array_column($frame['textLayers'], 'id'))->not->toContain('player');
    Console::withLayer('modal', fn() => Console::write('   ', 8, 6), 1020);
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $this->player->availableAction = null;
    expect($this->player->canAct)->toBeFalse();
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(getPlayerPromptRuns($frame))->toBe([])
        ->and($frame['viewport']['textLayerIds'])->not->toContain(PresentationLayerPolicy::FIELD_PROMPT_ID);
    $text = array_column($frame['textLayers'], null, 'id');
    expect($text['modal']['runs'][0]['text'])->toBe('   ')
        ->and(Console::charAt(8, 6))->toBe(' ');
    $this->player->$draw();
    expect($this->runtime->present($this->scene))->toBeFalse();
})->with(['render', 'renderPlayer']);

it('removes the prompt on either erase path even while staged presentation suppresses the player', function (string $erase, bool $suppressed) {
    $this->field->renderTheField();
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(Console::charAt(6, 5))->toBe('!');
    Console::withLayer('modal', fn() => Console::write(' ', 6, 5), 1020);
    expect(new ReflectionProperty(Console::class, 'layerCells')->getValue()[5][6]['layers'])
        ->toHaveKeys(['field-prompt', 'modal']);
    if ($suppressed) {
        $stage = new CinematicStageManager($this->scene);
        new ReflectionProperty(GameScene::class, 'cinematicStage')->setValue($this->scene, $stage);
        $stage->add(['id' => 'staged-hero', 'subject' => ['kind' => 'player'], 'sprite' => 'S']);
    }
    $erase === 'erase' ? $this->player->erase() : $this->player->erasePlayer($this->camera);
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(getPlayerPromptRuns($frame))->toBe([])->and(Console::charAt(6, 5))->toBe(' ');
    expect(array_column($frame['textLayers'], 'id'))->toContain('modal');
})->with(['erase', 'erasePlayer'])->with([false, true]);

it('clears the old prompt when either draw path becomes suppressed instead of returning with stale ownership', function (string $draw) {
    $this->field->renderTheField();
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $stage = new CinematicStageManager($this->scene);
    new ReflectionProperty(GameScene::class, 'cinematicStage')->setValue($this->scene, $stage);
    $stage->add(['id' => 'staged-hero', 'subject' => ['kind' => 'player'], 'sprite' => 'S']);
    $this->player->$draw();
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(getPlayerPromptRuns($frame))->toBe([]);
})->with(['render', 'renderPlayer']);

it('retires the map-owned action and prompt on real map load and transfer while retaining the destination world', function (bool $transfer) {
    $this->field->renderTheField();
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $this->manager->destination = createPlayerPromptMap('x');
    if ($transfer) {
        expect($this->scene->transferPlayer(new Location('next-map', new Vector2(7, 9), null), false))->toBeTrue();
    } else {
        $this->scene->loadMap('next-map', $this->player);
        $this->field->renderTheField();
    }
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect($this->player->canAct)->toBeFalse()->and(getPlayerPromptRuns($frame))->toBe([])
        ->and($frame['worlds']['map']['glyphRows']['map:terrain'][0][0]['glyph'])->toBe('x');
})->with([false, true]);

it('keeps terminal prompts above the terminal glyph even when graphical artwork and zoom are configured', function (string $draw) {
    Console::setTerminalOutputEnabled(true);
    $this->player->sprite = ['abc'];
    ob_start();
    try {
        $this->camera->renderMap();
        $this->player->$draw();
        expect($this->scene->getGraphicalFieldCellHeight())->toBeNull()
            ->and(Console::charAt(7, 7))->toBe('!')->and(Console::charAt(6, 5))->toBe('.');
        $this->player->availableAction = null;
        expect(Console::charAt(7, 7))->toBe('.');
    } finally { ob_end_clean(); }
})->with(['render', 'renderPlayer']);

it('anchors graphical prompts to the bottom-center tile rather than the terminal glyph width', function (string $draw) {
    $this->player->sprite = ['abc'];
    $this->player->$draw();
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(getPlayerPromptRuns($frame))->toBe([['row' => 5, 'column' => 6, 'text' => '!']]);
})->with(['render', 'renderPlayer']);

it('clips a graphical prompt above an off-screen head instead of pinning it to a screen edge', function (int $x, int $y) {
    $this->field->renderTheField();
    presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    $this->player->position->x = $x;
    $this->player->position->y = $y;
    $this->player->render();
    $frame = presentPlayerPromptFrame($this->runtime, $this->scene, $this->transport);
    expect(getPlayerPromptRuns($frame))->toBe([]);
})->with([[-1, 8], [6, 1]]);
