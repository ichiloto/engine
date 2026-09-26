<?php

use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayer;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

beforeEach(function () {
    $this->globals = [];
    foreach ([Console::class, InputManager::class, ConfigStore::class] as $class) {
        $this->globals[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
        new ReflectionProperty(Console::class, $key)->setValue(null, $value);
    }
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(135, 36);
    $this->runtime = null;
});

afterEach(function () {
    $this->runtime?->shutdown();
    foreach ($this->globals as $class => $values) {
        foreach ($values as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
});

it('centers a whole-cell field view without changing UI grid dimensions', function () {
    $grid = new RendererGridConfig(135, 36, 10, 20);
    $layout = new FieldViewport($grid, 2);
    $viewport = $layout->createViewport([new PresentationTextLayer('world', 0, []),
        new PresentationTextLayer('dialogue', 1000, [])], [], []);
    // 1350 x 720 pixels hold 14 x 7 field cells of 48 pixels at 2x (96 pixels); the remainder is split evenly.
    expect([$layout->columns, $layout->rows])->toBe([14, 7])
        ->and([$viewport->x, $viewport->y])->toBe([3.0, 24.0])
        ->and($viewport->textLayerIds)->toBe(['world'])
        ->and($viewport->clipRect->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 1350.0, 'height' => 720.0])
        ->and($grid->columns)->toBe(135);
    $viewport->assertWithin($grid);
});

it('sizes the field in 48-pixel cells scaled by zoom, independent of the text cell size', function (int $cellWidth, int $cellHeight, float $zoom, array $expected) {
    $layout = new FieldViewport(new RendererGridConfig(135, 36, $cellWidth, $cellHeight), $zoom);
    expect(FieldViewport::CELL_SIZE)->toBe(48)->and([$layout->columns, $layout->rows])->toBe($expected);
})->with([
    '10 x 20 text cells at 1x' => [10, 20, 1.0, [28, 15]],
    '10 x 20 text cells at 1.5x' => [10, 20, 1.5, [18, 10]],
    '10 x 20 text cells at 2x' => [10, 20, 2.0, [14, 7]],
    '16 x 24 text cells at 1x' => [16, 24, 1.0, [45, 18]],
    'a session smaller than one cell still shows one' => [1, 1, 8.0, [1, 1]],
]);

it('refuses invalid field zoom without inventing extra source cells', function (float $zoom) {
    expect(fn() => new FieldViewport(new RendererGridConfig(135, 36), $zoom))->toThrow(InvalidArgumentException::class);
})->with([0.0, 0.5, 8.1, INF, NAN]);

it('scales the above-sprite field prompt by identity without scaling HUD text at the same priority', function () {
    $layout = new FieldViewport(new RendererGridConfig(80, 40, 10, 20), 2);
    $text = [new PresentationTextLayer('player', 0, []),
        new PresentationTextLayer(PresentationLayerPolicy::FIELD_PROMPT_ID, 1010, []),
        new PresentationTextLayer('location-hud', 1010, []), new PresentationTextLayer('modal', 1020, [])];
    expect($layout->createViewport($text, [])->textLayerIds)->toBe(['player', 'field-prompt']);
    $changes = new \Ichiloto\Engine\IO\Console\ConsolePresentationChanges(80, 40, false,
        array_map(static fn($layer) => ['id' => $layer->id, 'layer' => $layer->layer, 'rows' => []], $text));
    expect($layout->createViewport($changes, [])->textLayerIds)->toBe(['player', 'field-prompt']);
});

it('keeps small maps centered and applies one transform to terrain and actors', function (float $zoom) {
    $grid = new RendererGridConfig(135, 36, 10, 20);
    $layout = new FieldViewport($grid, $zoom);
    // An 11 x 5 map is smaller than the field at every tested zoom (14 x 7 at 2x).
    $camera = new Camera(makeCameraTestScene(), $layout->columns, $layout->rows,
        worldSpace: array_fill(0, 5, str_repeat('.', 11)));
    $position = $camera->getScreenSpacePosition(new Vector2(5, 2));
    $sprite = new PresentationSprite('npc:fixture', 'actor.png', (int)$position->x, (int)$position->y,
        FieldViewport::CELL_SIZE, FieldViewport::CELL_SIZE);
    $tiles = new PresentationTileBatch('map:floor', 'floor.png', -100,
        [new SpriteSourceRect(0, 0, 16, 16)], [['column' => (int)$position->x, 'row' => (int)$position->y, 'source' => 0]]);
    $viewport = $layout->createViewport([], [$sprite], [$tiles]);
    expect($viewport->spriteIds)->toBe(['npc:fixture'])->and($viewport->tileBatchIds)->toBe(['map:floor']);
    $viewport->assertMembers([], [$sprite], [$tiles]);
    $origin = $camera->getScreenSpacePosition(new Vector2(0, 0));
    // Terrain and actors share the field pitch: one 48-pixel cell scaled by zoom.
    $pitch = FieldViewport::CELL_SIZE * $zoom;
    $centerX = $viewport->x + ($origin->x + 11 / 2) * $pitch;
    $centerY = $viewport->y + ($origin->y + 5 / 2) * $pitch;
    expect($origin->x)->toBeGreaterThan(0)->and($origin->y)->toBeGreaterThan(0)
        ->and(abs($centerX - 675))->toBeLessThanOrEqual($pitch / 2)
        ->and(abs($centerY - 360))->toBeLessThanOrEqual($pitch / 2)
        ->and($camera->getWorldSpacePosition($position))->toEqual(new Vector2(5, 2));
})->with([1.0, 1.5, 2.0]);

it('leaves a terminal camera with custom dimensions alone', function () {
    $scene = makeBareScene(GameScene::class);
    $camera = new Camera($scene, 42, 17);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['field' => ['zoom' => 2.0]]]));
    $scene->synchronizeFieldViewport();
    expect([$camera->screen->getWidth(), $camera->screen->getHeight()])->toBe([42, 17]);
});

it('validates viewport membership and prevents canvas mixing', function () {
    $clip = new CanvasRectangle(0, 0, 100, 100);
    expect(fn() => new PresentationViewport(2, 0, 0, $clip, ['a', 'a']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new StyledPresentationFrame(0, viewport: new PresentationViewport(2, 0, 0, $clip, ['missing'])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new StyledPresentationFrame(0, canvas: new PresentationCanvas(100, 100),
            viewport: new PresentationViewport(2, 0, 0, $clip)))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PresentationViewport(2, 150, 0, $clip)->assertWithin(new RendererGridConfig(10, 10, 10, 10)))
        ->toThrow(InvalidArgumentException::class);
});

it('uses the reduced camera for real field projection while UI and menu frames stay unscaled', function (bool $advertisesLegacyViewport) {
    $transport = new FakeRendererTransport();
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
        'capabilities' => $advertisesLegacyViewport ? ['frame_viewport'] : []], JSON_THROW_ON_ERROR))];
    $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
        cellWidth: 10, cellHeight: 20), $transport);
    $this->runtime->start('Field zoom', 135, 36);
    $game = $this->getMockBuilder(Game::class)->disableOriginalConstructor()->onlyMethods(['getRendererRuntime', '__destruct'])->getMock();
    $game->method('getRendererRuntime')->willReturn($this->runtime);
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
        ->onlyMethods(['getGame', 'getPresentationWorld'])->getMock();
    $scene->method('getGame')->willReturn($game);
    // The field's square cell travels with its retained world.
    $scene->method('getPresentationWorld')->willReturn(PresentationWorld::getFromLayers(
        new MapLayerSet([new MapLayer('terrain', 0, false, 'terrain', implode("\n", array_fill(0, 40, str_repeat('.', 100))))]), []));
    new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene,
        makeBareScene(\Ichiloto\Engine\Scenes\SceneManager::class));
    $camera = new Camera($scene, 135, 36, worldSpace: array_fill(0, 40, str_repeat('.', 100)));
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(['getGraphicalSpriteDefinition'])->getMock();
    $player->method('getGraphicalSpriteDefinition')->willReturn(null);
    new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
    new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(99, 39));
    new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
    $field = makeBareScene(FieldState::class);
    new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['field' => ['zoom' => 2.0]]]));
    $scene->synchronizeFieldViewport();
    // Retained presentation removes the old optional viewport-capability fallback.
    expect([$camera->screen->getWidth(), $camera->screen->getHeight()])->toBe([14, 7]);
    $camera->resetPosition($player);
    // The camera clamps to the 100 x 40 world's bottom-right corner.
    expect([$camera->position->x, $camera->position->y])->toBe([86.0, 33.0]);
    $screen = $camera->getScreenSpacePosition($player->position);
    expect($camera->getWorldSpacePosition($screen))->toEqual($player->position);
    Console::recomposeFrame(function () use ($camera) {
        $camera->renderMap();
        Console::withLayer('dialogue', fn() => Console::write('Still normal size', 10, 30), 1000);
    });
    $this->runtime->present($scene);
    $frames = RetainedFrameState::replay($transport->sent);
    $payload = $frames[array_key_last($frames)];
    expect($payload['viewport']['scale'])->toBe(2.0)
        ->and($payload['viewport']['worldId'])->toBe('map')
        ->and($payload['viewport']['textLayerIds'])->toBe(['world']);
    $text = array_column($payload['textLayers'], null, 'id');
    expect($text['dialogue']['runs'][0]['row'])->toBe(30);
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, makeBareScene(MainMenuState::class));
    Console::recomposeFrame(fn() => Console::write('Menu', 0, 0));
    $this->runtime->present($scene);
    $frames = RetainedFrameState::replay($transport->sent);
    expect($frames[array_key_last($frames)])->not->toHaveKey('viewport');
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
    $scene->synchronizeFieldViewport();
    Console::recomposeFrame($camera->renderMap(...));
    $this->runtime->present($scene);
    $frames = RetainedFrameState::replay($transport->sent);
    expect($frames[array_key_last($frames)]['viewport']['scale'])->toBe(2.0);
    // At 1x a field cell is still 48 pixels, not a 10 x 20 text cell: the camera shows 28 x 15 cells
    // and the field text needs the viewport transform to be drawn at the field pitch.
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['field' => ['zoom' => 1.0]]]));
    $scene->synchronizeFieldViewport();
    expect([$camera->screen->getWidth(), $camera->screen->getHeight()])->toBe([28, 15]);
    Console::recomposeFrame($camera->renderMap(...));
    $this->runtime->present($scene);
    $frames = RetainedFrameState::replay($transport->sent);
    $payload = $frames[array_key_last($frames)];
    expect($payload['viewport']['scale'] ?? null)->toBe(1.0)
        ->and($payload['viewport']['textLayerIds'] ?? null)->toBe(['world']);
})->with([false, true]);

it('degrades the field to the text grid when no retained world carries the field cell', function () {
    $transport = new FakeRendererTransport();
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
        'capabilities' => []], JSON_THROW_ON_ERROR))];
    $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
        cellWidth: 10, cellHeight: 20), $transport);
    $this->runtime->start('No world', 135, 36);
    $game = $this->getMockBuilder(Game::class)->disableOriginalConstructor()->onlyMethods(['getRendererRuntime', '__destruct'])->getMock();
    $game->method('getRendererRuntime')->willReturn($this->runtime);
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
        ->onlyMethods(['getGame', 'getPresentationWorld'])->getMock();
    $scene->method('getGame')->willReturn($game);
    $scene->method('getPresentationWorld')->willReturn(null);
    new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene,
        makeBareScene(\Ichiloto\Engine\Scenes\SceneManager::class));
    $camera = new Camera($scene, 135, 36, worldSpace: array_fill(0, 40, str_repeat('.', 100)));
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['field' => ['zoom' => 2.0]]]));
    $scene->synchronizeFieldViewport();

    // A map beyond the world budget is drawn as plain text at the text grid,
    // exactly as the terminal draws it: no shrunken camera and no viewport.
    expect([$camera->screen->getWidth(), $camera->screen->getHeight()])->toBe([135, 36])
        ->and($scene->isGraphicalFieldPresented())->toBeFalse();
});
