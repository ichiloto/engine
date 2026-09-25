<?php

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

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

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
    expect([$layout->columns, $layout->rows])->toBe([67, 18])
        ->and([$viewport->x, $viewport->y])->toBe([5.0, 0.0])
        ->and($viewport->textLayerIds)->toBe(['world'])
        ->and($viewport->clipRect->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 1350.0, 'height' => 720.0])
        ->and($grid->columns)->toBe(135);
    $viewport->assertWithin($grid);
});

it('refuses invalid field zoom without inventing extra source cells', function (float $zoom) {
    expect(fn() => new FieldViewport(new RendererGridConfig(135, 36), $zoom))->toThrow(InvalidArgumentException::class);
})->with([0.0, 0.5, 8.1, INF, NAN]);

it('keeps small maps centered and applies one transform to terrain and actors', function (float $zoom) {
    $grid = new RendererGridConfig(135, 36, 10, 20);
    $layout = new FieldViewport($grid, $zoom);
    $camera = new Camera(makeCameraTestScene(), $layout->columns, $layout->rows,
        worldSpace: array_fill(0, 14, str_repeat('.', 33)));
    $position = $camera->getScreenSpacePosition(new Vector2(16, 7));
    $sprite = new PresentationSprite('npc:fixture', 'actor.png', (int)$position->x, (int)$position->y, 56, 56);
    $tiles = new PresentationTileBatch('map:floor', 'floor.png', -100,
        [new SpriteSourceRect(0, 0, 16, 16)], [['column' => (int)$position->x, 'row' => (int)$position->y, 'source' => 0]]);
    $viewport = $layout->createViewport([], [$sprite], [$tiles]);
    expect($viewport->spriteIds)->toBe(['npc:fixture'])->and($viewport->tileBatchIds)->toBe(['map:floor']);
    $viewport->assertMembers([], [$sprite], [$tiles]);
    $origin = $camera->getScreenSpacePosition(new Vector2(0, 0));
    $centerX = $viewport->x + ($origin->x + 33 / 2) * $grid->cellWidth * $zoom;
    $centerY = $viewport->y + ($origin->y + 14 / 2) * $grid->cellHeight * $zoom;
    expect(abs($centerX - 675))->toBeLessThanOrEqual($grid->cellWidth * $zoom / 2)
        ->and(abs($centerY - 360))->toBeLessThanOrEqual($grid->cellHeight * $zoom / 2)
        ->and($camera->getWorldSpacePosition($position))->toEqual(new Vector2(16, 7));
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

it('uses the reduced camera for real field projection while UI and menu frames stay unscaled', function (bool $supported) {
    $transport = new FakeRendererTransport();
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
        'capabilities' => $supported ? ['frame_viewport'] : []], JSON_THROW_ON_ERROR))];
    $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
        cellWidth: 10, cellHeight: 20), $transport);
    $this->runtime->start('Field zoom', 135, 36);
    $game = $this->getMockBuilder(Game::class)->disableOriginalConstructor()->onlyMethods(['getRendererRuntime', '__destruct'])->getMock();
    $game->method('getRendererRuntime')->willReturn($this->runtime);
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getGame'])->getMock();
    $scene->method('getGame')->willReturn($game);
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
    expect([$camera->screen->getWidth(), $camera->screen->getHeight()])->toBe($supported ? [67, 18] : [135, 36]);
    $camera->resetPosition($player);
    expect([$camera->position->x, $camera->position->y])->toBe($supported ? [33.0, 22.0] : [0.0, 4.0]);
    $screen = $camera->getScreenSpacePosition($player->position);
    expect($camera->getWorldSpacePosition($screen))->toEqual($player->position);
    Console::recomposeFrame(function () use ($camera) {
        $camera->renderMap();
        Console::withLayer('dialogue', fn() => Console::write('Still normal size', 10, 30), 1000);
    });
    $this->runtime->present($scene);
    $payload = end($transport->sent)->payload;
    expect(isset($payload['viewport']))->toBe($supported);
    if ($supported) {
        expect($payload['viewport']['scale'])->toBe(2.0)
            ->and($payload['viewport']['textLayerIds'])->toBe(['world']);
    }
    $text = array_column($payload['textLayers'], null, 'id');
    expect($text['dialogue']['runs'][0]['row'])->toBe(30);
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, makeBareScene(MainMenuState::class));
    Console::recomposeFrame(fn() => Console::write('Menu', 0, 0));
    $this->runtime->present($scene);
    expect(end($transport->sent)->payload)->not->toHaveKey('viewport');
    new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
    $scene->synchronizeFieldViewport();
    Console::recomposeFrame($camera->renderMap(...));
    $this->runtime->present($scene);
    expect(isset(end($transport->sent)->payload['viewport']))->toBe($supported);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['field' => ['zoom' => 1.0]]]));
    $scene->synchronizeFieldViewport();
    expect([$camera->screen->getWidth(), $camera->screen->getHeight()])->toBe([135, 36]);
    Console::recomposeFrame($camera->renderMap(...));
    $this->runtime->present($scene);
    expect(end($transport->sent)->payload)->not->toHaveKey('viewport');
})->with([false, true]);
