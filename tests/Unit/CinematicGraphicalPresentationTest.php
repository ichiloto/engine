<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\spriteSheetData;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
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
});

it('projects staged sheets with independent identities and PHP-owned route animation', function () {
  $entry = ['id' => 'one', 'sprite' => '@', 'x' => 7, 'y' => 4, 'sprites2d' => spriteSheetData()];
  $one = $this->stage->add($entry);
  $two = $this->stage->add(array_replace($entry, ['id' => 'two', 'x' => 9]));
  $this->stage->move('one', Vector2::right());
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(256)
    ->and($two->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
  $this->stage->move('one', Vector2::right());
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(512);
  $this->camera->moveTo(3, 2);
  $sprite = $this->projector->project($one, $this->camera);
  expect([$sprite->id, $sprite->x, $sprite->y, $sprite->asset])->toBe(['staged:one', 6, 2, 'Graphics/east.png'])
    ->and($two->getGraphicalSpriteId())->toBe('staged:two');
  $copy = $one->getGraphicalSpriteWorldPosition();
  $copy->x = 999;
  expect($one->position->x)->toBe(9.0);
  $this->stage->move('one', Vector2::up(), faceOnly: true);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0)
    ->and($one->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/north.png');
  $this->stage->move('one', Vector2::up());
  $this->stage->advanceGraphicalAnimation(0.16);
  expect($one->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
});

it('keeps terminal fallbacks intact and masks only the graphical actor provenance', function () {
  $one = $this->stage->add(['id' => 'one', 'sprite' => '@', 'x' => 7, 'y' => 4,
    'sprites2d' => ['asset' => 'pose.png', 'width' => 56, 'height' => 56, 'layer' => 100,
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
    'sprites2d' => ['asset' => 'pose.png', 'width' => 56, 'height' => 56, 'layer' => 999]]);
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
  $frame = $transport->sent[0]->payload;
  expect($frame['sprites'])->toHaveCount(1)
    ->and(array_column($frame['textLayers'], 'layer'))->toContain(1020, 3000)
    ->and(Console::charAt(7, 4))->toBe('#');
  $this->presentation->clear();
  $render();
  expect(array_column($transport->sent[1]->payload['textLayers'], 'layer'))->not->toContain(1020, 3000)
    ->and($transport->sent[1]->payload['sprites'])->toBe($frame['sprites']);
});

it('validates staged graphics at authoring boundaries without requiring an asset on disk', function () {
  $definition = CinematicDefinition::fromArrays(['id' => 'sheet-cast', 'name' => 'Sheet cast',
    'cast' => [['id' => 'one', 'sprite' => '@', 'sprites2d' => spriteSheetData()]]], [['type' => 'wait', 'seconds' => 1]]);
  expect($definition->cast[0]['sprites2d'])->toBe(spriteSheetData());
});

it('rejects malformed staged graphics with an actionable cast path', function ($graphics) {
  expect(fn() => CinematicDefinition::fromArrays(['id' => 'invalid-cast', 'name' => 'Invalid cast',
    'cast' => [['id' => 'one', 'sprite' => '@', 'sprites2d' => $graphics]]], []))
    ->toThrow(InvalidArgumentException::class, 'cast[1]');
})->with([
  'wrong type' => ['image.png'],
  'missing fields' => [['asset' => 'pose.png']],
  'bad path' => [['asset' => '../pose.png', 'width' => 56, 'height' => 56]],
  'reserved UI layer' => [['asset' => 'pose.png', 'width' => 56, 'height' => 56, 'layer' => 1000]],
  'bad crop' => [['asset' => 'pose.png', 'width' => 56, 'height' => 56,
    'sourceRect' => ['x' => -1, 'y' => 0, 'width' => 256, 'height' => 256]]],
]);
