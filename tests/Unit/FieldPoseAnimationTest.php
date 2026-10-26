<?php

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Animations\Field\FieldPosePlayback;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function makeFieldPoseData(array $animation = []): array
{
  return ['asset' => 'Frames.png', 'layer' => 123, 'cells' => ['width' => 2, 'height' => 3],
    'animation' => array_replace(['columns' => 3, 'rows' => 2, 'frames' => [2, 4, 1],
      'fps' => 10, 'loop' => true, 'restFrame' => 5], $animation)];
}

beforeEach(function () {
  $this->assetRoot = createTestDirectory('field-pose-');
  $this->staticBefore = [];
  foreach ([Debug::class, PngAssetPreflight::class] as $class) {
    $this->staticBefore[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Debug::configure(['log_directory' => $this->assetRoot . '/logs']);
  writeTestPng($this->assetRoot . '/Frames.png', 24, 20);
});

afterEach(function () {
  foreach ($this->staticBefore as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
});

it('selects authored row-major frames without changing field footprint or advancing on reads', function () {
  $pose = FieldPoseAnimation::fromArray(makeFieldPoseData());
  foreach ([[0, [16, 0]], [.1, [8, 10]], [.2, [8, 0]], [.3, [16, 0]]] as [$time, $crop]) {
    $frame = $pose->getFrame($this->assetRoot, $time);
    expect([$frame->sourceRect->x, $frame->sourceRect->y])->toBe($crop)
      ->and([$frame->sourceRect->width, $frame->sourceRect->height])->toBe([8, 10])
      ->and([$frame->width, $frame->height, $frame->layer, $frame->asset])->toBe([96, 144, 123, 'Frames.png'])
      ->and($pose->getFrame($this->assetRoot, $time))->toEqual($frame);
  }
  $rest = $pose->getFrame($this->assetRoot, .1, true);
  expect([$rest->sourceRect->x, $rest->sourceRect->y])->toBe([16, 10])
    ->and($pose->frames)->toBe([2, 4, 1]);
  expect(fn() => $pose->columns = 2)->toThrow(Error::class)
    ->and(fn() => $pose->frames[0] = 1)->toThrow(Error::class);
});

it('uses an optional crop as the sheet region rather than one fixed frame', function () {
  writeTestPng($this->assetRoot . '/Frames.png', 40, 40);
  $data = makeFieldPoseData();
  $data['sourceRect'] = ['x' => 4, 'y' => 6, 'width' => 24, 'height' => 20];
  $frame = FieldPoseAnimation::fromArray($data)->getFrame($this->assetRoot, .1);
  expect([$frame->sourceRect->x, $frame->sourceRect->y, $frame->sourceRect->width, $frame->sourceRect->height])
    ->toBe([12, 16, 8, 10]);
  $data['sourceRect']['x'] = 30;
  expect(fn() => FieldPoseAnimation::fromArray($data)->getFrame($this->assetRoot, 0))
    ->toThrow(InvalidArgumentException::class, 'current field pose');
});

it('keeps default animation settings explicit and leaves still images and walking sheets compatible', function () {
  $pose = FieldPoseAnimation::fromArray(['asset' => 'Frames.png', 'animation' => ['frames' => [0]]]);
  expect([$pose->columns, $pose->rows, $pose->fps, $pose->loop, $pose->restFrame])->toBe([1, 1, 8, true, 0])
    ->and(CinematicStageManager::getGraphicalSprites(['asset' => 'Frames.png']))->toBeInstanceOf(GraphicalSpriteDefinition::class)
    ->and(CinematicStageManager::getGraphicalSprites(characterSheetData()))->toBeInstanceOf(CharacterSheet::class)
    ->and(CinematicStageManager::graphicalSprites(makeFieldPoseData()))->toEqual(FieldPoseAnimation::fromArray(makeFieldPoseData()));
  expect(fn() => CinematicStageManager::getGraphicalSprites([...characterSheetData(), 'animation' => ['frames' => [0]]]))
    ->toThrow(InvalidArgumentException::class);
  $entry = ['id' => 'pulse', 'sprite' => 'P', 'sprites2d' => makeFieldPoseData()];
  CinematicScriptValidator::validate([['type' => 'stage_actor', ...$entry]]);
  expect(CinematicDefinition::fromArrays(['id' => 'pose', 'name' => 'Pose', 'cast' => [$entry]], [])
    ->cast[0]['sprites2d'])->toBe(makeFieldPoseData());
  $schema = CinematicCommandSchema::export()['stagedPoseAnimation'];
  expect($schema['fields'])->toBe(FieldPoseAnimation::FIELDS)
    ->and([$schema['maxGridAxis'], $schema['maxFrames'], $schema['maxFps'], $schema['defaultFps']])
    ->toBe([64, 10000, 120, 8]);
});

it('holds the last non-looping frame and allows repeated cells in authored order', function () {
  $pose = FieldPoseAnimation::fromArray(makeFieldPoseData(['frames' => [4, 4, 2], 'loop' => false]));
  foreach ([0, .1, .2, 100, PHP_FLOAT_MAX] as $seconds) {
    expect($pose->getFrame($this->assetRoot, $seconds)->sourceRect->x)->toBe($seconds < .2 ? 8 : 16);
  }
  $playback = new FieldPosePlayback($pose, $this->assetRoot, 'Fixture');
  $playback->advance(PHP_FLOAT_MAX); $playback->advance(PHP_FLOAT_MAX);
  expect($playback->getFrame()->sourceRect->x)->toBe(16);
});

it('samples exact boundaries at every supported rate and never changes a frame ahead of its boundary', function (int $fps) {
  $pose = FieldPoseAnimation::fromArray(makeFieldPoseData(['fps' => $fps]));
  $playback = new FieldPosePlayback($pose, $this->assetRoot, 'Fixture');
  for ($tick = 0; $tick <= 180; $tick++) {
    if ($tick > 0) { $playback->advance(1 / 60); }
    $index = intdiv($tick * $fps, 60);
    $source = $pose->frames[$index % count($pose->frames)];
    $frame = $pose->getFrame($this->assetRoot, $tick / 60);
    expect([$frame->sourceRect->x, $frame->sourceRect->y])->toBe([$source % 3 * 8, intdiv($source, 3) * 10]);
    expect($playback->getFrame()->sourceRect)->toEqual($frame->sourceRect);
  }
  expect($pose->getFrame($this->assetRoot, (1 - 1e-8) / $fps)->sourceRect->x)->toBe(16)
    ->and($pose->getFrame($this->assetRoot, 1 / $fps)->sourceRect->y)->toBe(10);
})->with(range(1, 120));

it('owns independent clocks with partitioned deltas, pause, reduced motion and irreversible release', function () {
  $pose = FieldPoseAnimation::fromArray(makeFieldPoseData());
  $playback = new FieldPosePlayback($pose, $this->assetRoot, 'Fixture');
  $other = new FieldPosePlayback($pose, $this->assetRoot, 'Other');
  $playback->advance(.05); $playback->advance(.05);
  expect($playback->getFrame()->sourceRect->y)->toBe(10)->and($other->getFrame()->sourceRect->x)->toBe(16);
  $playback->pause(); $playback->advance(99);
  expect($playback->getFrame()->sourceRect->y)->toBe(10);
  $playback->resume(); $playback->advance(99, true);
  expect($playback->getFrame(true)->sourceRect->x)->toBe(16)->and($playback->getFrame(true)->sourceRect->y)->toBe(10)
    ->and($playback->getFrame()->sourceRect->x)->toBe(8)->and($playback->getFrame()->sourceRect->y)->toBe(10);
  $playback->advance(.1);
  expect($playback->getFrame()->sourceRect->y)->toBe(0);
  $playback->advance(PHP_FLOAT_MAX);
  expect($playback->getFrame())->not->toBeNull();
  $playback->release(); $playback->resume(); $playback->advance(.1);
  expect($playback->getFrame())->toBeNull()->and($playback->getFrame(true))->toBeNull();
});

it('reads replaceable artwork dimensions, diagnoses invalid grids once and recovers without restaging', function () {
  $playback = new FieldPosePlayback(FieldPoseAnimation::fromArray(makeFieldPoseData()), $this->assetRoot, 'Fixture');
  $playback->advance(.1);
  foreach ([[24, 20, 8, 10], [48, 30, 16, 15]] as [$width, $height, $cellWidth, $cellHeight]) {
    writeTestPng($this->assetRoot . '/Frames.png', $width, $height);
    $frame = $playback->getFrame();
    expect([$frame->sourceRect->x, $frame->sourceRect->y, $frame->sourceRect->width, $frame->sourceRect->height])
      ->toBe([$cellWidth, $cellHeight, $cellWidth, $cellHeight])
      ->and([$frame->width, $frame->height])->toBe([96, 144]);
  }
  writeTestPng($this->assetRoot . '/Frames.png', 25, 20);
  expect($playback->getFrame())->toBeNull()->and($playback->getFrame())->toBeNull();
  expect(substr_count(file_get_contents($this->assetRoot . '/logs/warning.log'), 'Fixture sprites2d unavailable'))->toBe(1);
  writeTestPng($this->assetRoot . '/Frames.png', 30, 40);
  expect($playback->getFrame()->sourceRect->width)->toBe(10);
  unlink($this->assetRoot . '/Frames.png');
  expect($playback->getFrame())->toBeNull();
  writeTestPng($this->assetRoot . '/Frames.png', 36, 20);
  expect($playback->getFrame()->sourceRect->width)->toBe(12);
});

it('rejects invalid animation data at the shared runtime, command and cast boundaries', function (array $invalid) {
  $data = makeFieldPoseData($invalid);
  $entry = ['id' => 'pulse', 'sprite' => 'P', 'sprites2d' => $data];
  expect(fn() => FieldPoseAnimation::fromArray($data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => CinematicStageManager::getGraphicalSprites($data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => CinematicScriptValidator::validate([['type' => 'stage_actor', ...$entry]]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => CinematicDefinition::fromArrays(['id' => 'pose', 'name' => 'Pose', 'cast' => [$entry]], []))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'unknown field' => [['hash' => 'old-art']], 'empty order' => [['frames' => []]],
  'sparse order' => [['frames' => [1 => 0]]], 'excessive order' => [['frames' => array_fill(0, 10001, 0)]],
  'string source' => [['frames' => ['1']]], 'fraction source' => [['frames' => [1.1]]],
  'boolean source' => [['frames' => [true]]], 'negative source' => [['frames' => [-1]]],
  'source outside grid' => [['frames' => [6]]], 'zero columns' => [['columns' => 0]],
  'too many columns' => [['columns' => 65]], 'fraction rows' => [['rows' => 1.5]],
  'too many rows' => [['rows' => 65]], 'zero fps' => [['fps' => 0]], 'excessive fps' => [['fps' => 121]],
  'string fps' => [['fps' => '10']], 'nonboolean loop' => [['loop' => 1]],
  'rest outside grid' => [['restFrame' => 6]], 'string rest' => [['restFrame' => '0']],
  'null columns' => [['columns' => null]], 'null rows' => [['rows' => null]],
  'null fps' => [['fps' => null]], 'null loop' => [['loop' => null]], 'null rest' => [['restFrame' => null]],
]);

it('retains existing path, footprint and crop validation for animated field images', function (array $invalid) {
  expect(fn() => FieldPoseAnimation::fromArray(array_replace(makeFieldPoseData(), $invalid)))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'no frame order' => [['animation' => []]], 'nonarray animation' => [['animation' => true]],
  'unsafe path' => [['asset' => '../outside.png']], 'pixel width' => [['width' => 100]],
  'invalid footprint' => [['cells' => ['width' => 0, 'height' => 1]]],
  'invalid crop' => [['sourceRect' => ['x' => -1, 'y' => 0, 'width' => 4, 'height' => 4]]],
]);

it('refuses invalid elapsed time and deltas even after release', function (float $seconds) {
  $pose = FieldPoseAnimation::fromArray(makeFieldPoseData());
  $playback = new FieldPosePlayback($pose, $this->assetRoot, 'Fixture');
  $playback->release();
  expect(fn() => $pose->getFrame($this->assetRoot, $seconds))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $playback->advance($seconds))->toThrow(InvalidArgumentException::class);
})->with([-1.0, INF, NAN]);
