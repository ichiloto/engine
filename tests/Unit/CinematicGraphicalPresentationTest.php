<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->consoleBefore = new ReflectionClass(Console::class)->getStaticProperties();
  $this->cursorBefore = new ReflectionClass(Cursor::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  ob_start();
  Console::syncDimensions(24, 8);
  Console::setLayerTracking(true);
  $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  // Staged character sheets are checked against the running renderer's asset root.
  $this->assetRoot = sys_get_temp_dir() . '/ichiloto-cinematic-graphics-' . bin2hex(random_bytes(4));
  writeCharacterSheetPng($this->assetRoot . '/' . characterSheetData()['sheet'], 48, 48);
  [$game] = makeSceneAudioGame();
  $game->useRendererRuntime(new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']),
    $this->assetRoot), new FakeRendererTransport()));
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty(SceneManager::class, 'game')->setValue($manager, $game);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($this->scene, $manager);
  $this->camera = new Camera($this->scene, 24, 8, worldSpace: array_fill(0, 30, str_repeat('.', 60)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->stage = new CinematicStageManager($this->scene);
  $this->presentation = new CinematicPresentationManager($this->scene);
  $this->projector = new GraphicalSpriteProjector();
});

afterEach(function () {
  ob_end_clean();
  foreach ([Console::class => $this->consoleBefore, Cursor::class => $this->cursorBefore] as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
  $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->assetRoot,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($paths as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
  rmdir($this->assetRoot);
});

it('projects staged sheets with independent identities and PHP-owned route animation', function () {
  $entry = ['id' => 'one', 'sprite' => '@', 'x' => 7, 'y' => 4, 'sprites2d' => characterSheetData()];
  $one = $this->stage->add($entry);
  $two = $this->stage->add(array_replace($entry, ['id' => 'two', 'x' => 9]));
  // 48 x 48 frames: east is row 2; strides step through patterns 2 then 1 while the other actor stands on 1.
  $this->stage->move('one', Vector2::right());
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 96, 'y' => 96, 'width' => 48, 'height' => 48])
    ->and($two->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 48, 'y' => 0, 'width' => 48, 'height' => 48]);
  $this->stage->move('one', Vector2::right());
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(48);
  $this->camera->moveTo(3, 2);
  $sprite = $this->projector->project($one, $this->camera);
  expect([$sprite->id, $sprite->x, $sprite->y, $sprite->asset])->toBe(['staged:one', 6, 2, characterSheetData()['sheet']])
    ->and([$sprite->width, $sprite->height])->toBe([FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE])
    ->and($two->getGraphicalSpriteId())->toBe('staged:two');
  $copy = $one->getGraphicalSpriteWorldPosition();
  $copy->x = 999;
  expect($one->position->x)->toBe(9.0);
  $this->stage->move('one', Vector2::up(), faceOnly: true);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 48, 'y' => 144, 'width' => 48, 'height' => 48]);
  $this->stage->move('one', Vector2::up());
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(96);
  $this->stage->advanceGraphicalAnimation(CharacterWalkAnimation::STRIDE_SECONDS);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(48);
});

it('keeps terminal fallbacks intact and masks only the graphical actor provenance', function () {
  $one = $this->stage->add(['id' => 'one', 'sprite' => '@', 'x' => 7, 'y' => 4,
    'sprites2d' => ['asset' => 'pose.png', 'layer' => 100,
      'sourceRect' => ['x' => 256, 'y' => 0, 'width' => 256, 'height' => 256]]]);
  $legacy = $this->stage->add(['id' => 'legacy', 'sprite' => 'L', 'x' => 8, 'y' => 4]);
  Console::recomposeFrame(function (): void {
    Console::write(str_repeat('.', 24), 0, 4);
    $this->stage->render();
  });
  $terminal = Console::snapshot();
  $graphical = Console::snapshot([$one->getGraphicalSpriteId()]);
  expect(Console::charAt(7, 4))->toBe('@')->and(Console::charAt(8, 4))->toBe('L')
    ->and($graphical->rows[4][7])->toBe('.')->and($graphical->rows[4][8])->toBe('L')
    ->and($this->projector->project($legacy, $this->camera))->toBeNull()
    ->and($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(256)
    ->and(Console::snapshot())->toEqual($terminal);
  $this->stage->hide('one');
  expect($this->projector->project($one, $this->camera))->toBeNull();
  $this->stage->show('one');
  expect($this->projector->project($one, $this->camera)->id)->toBe('staged:one');
  $this->stage->remove('one');
  expect($this->stage->all())->toBe([$legacy]);
  $this->stage->clear();
  expect($this->stage->all())->toBe([]);
});

it('emits opaque cinematic overlays and covers above world sprites and clears them in the next snapshot', function () {
  $actor = $this->stage->add(['id' => 'one', 'sprite' => '@', 'x' => 7, 'y' => 4,
    'sprites2d' => ['asset' => 'pose.png', 'layer' => 999]]);
  $transport = new FakeRendererTransport();
  $output = new RendererPresentation(new RendererClient($transport), new RendererGridConfig(24, 8));
  $this->presentation->showOverlay('narration', 'A test line.');
  $this->presentation->hideField('#');
  $render = function () use ($actor, $output): void {
    Console::recomposeFrame(function (): void {
      $this->stage->render();
      $this->presentation->render();
    });
    $output->present(Console::presentationSnapshot([$actor->getGraphicalSpriteId()]),
      [$this->projector->project($actor, $this->camera)]);
  };
  $render();
  $frame = Tests\Support\Rendering\RetainedFrameState::replay($transport->sent)[0];
  expect($frame['sprites'])->toHaveCount(1)
    ->and(array_column($frame['textLayers'], 'layer'))->toContain(1020, 3000)
    ->and(Console::charAt(7, 4))->toBe('#');
  $this->presentation->clear();
  $render();
  $restored = Tests\Support\Rendering\RetainedFrameState::replay($transport->sent)[1];
  expect(array_column($restored['textLayers'], 'layer'))->not->toContain(1020, 3000)
    ->and($restored['sprites'])->toBe($frame['sprites'])
    ->and(array_column($transport->sent[1]->payload['operations'], 'kind'))->not->toContain('sprite');
});

it('validates staged graphics at authoring boundaries without requiring an asset on disk', function () {
  $sheet = characterSheetData('Graphics/Characters/Absent.png', 3);
  $pose = ['asset' => 'Graphics/Poses/Absent.png', 'cells' => ['width' => 2, 'height' => 3]];
  $definition = CinematicDefinition::fromArrays(['id' => 'sheet-cast', 'name' => 'Sheet cast',
    'cast' => [['id' => 'one', 'sprite' => '@', 'sprites2d' => $sheet], ['id' => 'two', 'sprite' => '@', 'sprites2d' => $pose]]],
    [['type' => 'wait', 'seconds' => 1]]);
  expect($definition->cast[0]['sprites2d'])->toBe($sheet)->and($definition->cast[1]['sprites2d'])->toBe($pose);
});

it('rejects malformed staged graphics with an actionable cast path', function ($graphics) {
  expect(fn() => CinematicDefinition::fromArrays(['id' => 'invalid-cast', 'name' => 'Invalid cast',
    'cast' => [['id' => 'one', 'sprite' => '@', 'sprites2d' => $graphics]]], []))
    ->toThrow(InvalidArgumentException::class, 'cast[1]');
})->with([
  'wrong type' => ['image.png'],
  'missing asset' => [['layer' => 100]],
  'authored pixel size' => [['asset' => 'pose.png', 'width' => 56, 'height' => 56]],
  'authored anchor' => [['asset' => 'pose.png', 'anchor' => 'bottom_center']],
  'bad path' => [['asset' => '../pose.png']],
  'reserved UI layer' => [['asset' => 'pose.png', 'layer' => 1000]],
  'bad crop' => [['asset' => 'pose.png', 'sourceRect' => ['x' => -1, 'y' => 0, 'width' => 256, 'height' => 256]]],
  'empty footprint' => [['asset' => 'pose.png', 'cells' => ['width' => 0, 'height' => 1]]],
  'per-direction images' => [['north' => ['asset' => 'north.png'], 'east' => ['asset' => 'east.png'],
    'south' => ['asset' => 'south.png'], 'west' => ['asset' => 'west.png']]],
  'sheet with authored size' => [['sheet' => 'Graphics/Characters/Heroes.png', 'width' => 48]],
  'sheet index out of range' => [['sheet' => 'Graphics/Characters/Heroes.png', 'index' => 8]],
  'sheet unsafe path' => [['sheet' => '../Heroes.png']],
  'sheet reserved UI layer' => [['sheet' => 'Graphics/Characters/Heroes.png', 'layer' => 1000]],
]);
