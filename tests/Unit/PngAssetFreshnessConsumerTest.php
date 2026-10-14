<?php

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheetAssetGuard;
use function Tests\Support\Rendering\createPaddedPngBytes;
use function Tests\Support\Rendering\replacePngWithCollidingFileFacts;

require_once __DIR__ . '/../Support/Rendering/PngFreshnessFixtures.php';

beforeEach(function () { $this->root = createTestDirectory('png-freshness-consumers-'); });

it('updates shared field and battler pose crops without changing authored identity or display size', function (bool $reduced) {
  $asset = '$person.png';
  $guard = new CharacterSheetAssetGuard($this->root, 'Synthetic sheet');
  $sheet = new CharacterSheet($asset);
  $battle = new BattlerPose($asset, columns: 3, rows: 4, frames: [1], restFrame: 2);
  $source = ['asset' => $asset, 'animation' => ['columns' => 3, 'rows' => 4, 'frames' => [1], 'restFrame' => 2]];
  $field = FieldPoseAnimation::fromArray($source);
  $collision = replacePngWithCollidingFileFacts($this->root . '/' . $asset,
    createPaddedPngBytes(24, 32), createPaddedPngBytes(48, 16), fn() => [
      $guard->getFrameSize($sheet), $field->getFrame($this->root, 0, $reduced), $battle->getArtwork($this->root, 0, $reduced)]);
  $nextField = $field->getFrame($this->root, 0, $reduced);
  $nextBattle = $battle->getArtwork($this->root, 0, $reduced);
  expect($collision['before'])->toBe($collision['after'])
    ->and($guard->getFrameSize($sheet))->toBe(['width' => 16, 'height' => 4])
    ->and($nextField->sourceRect->width)->toBe(16)->and($nextField->sourceRect->height)->toBe(4)
    ->and($nextField->sourceRect->x)->toBe($reduced ? 32 : 16)
    ->and($nextBattle->sourceRect->toArray())->toBe($nextField->sourceRect->toArray())
    ->and($nextField->width)->toBe($collision['primed'][1]->width)
    ->and($nextField->asset)->toBe($asset)->and($nextBattle->asset)->toBe($asset)
    ->and($source)->toBe(['asset' => $asset, 'animation' => ['columns' => 3, 'rows' => 4, 'frames' => [1], 'restFrame' => 2]]);
})->with([false, true]);

it('refreshes canvas budgets and mutable nine-slice source bounds through the shared preflight', function () {
  $image = new CanvasImage('synthetic', 'panel.png', new CanvasRectangle(0, 0, 100, 100));
  $collision = replacePngWithCollidingFileFacts($this->root . '/panel.png',
    createPaddedPngBytes(12, 8), createPaddedPngBytes(24, 16),
    fn() => CanvasImagePreflight::inspect([$image], $this->root));
  $budget = CanvasImagePreflight::inspect([$image], $this->root);
  $texture = CanvasNineSlice::getFromPng($this->root, 'panel.png', 4, 4, 4, 4);
  expect($collision['before'])->toBe($collision['after'])
    ->and($budget['sources'])->toBe(['panel.png' => 24 * 16 * 4])
    ->and(array_sum($budget['regions']))->toBe(28 * 20 * 4)
    ->and($texture->source->width)->toBe(24)->and($texture->source->height)->toBe(16);
});

it('recompiles and draws a current stage crop while retaining source identity and authored placement', function () {
  $timeline = ['fps' => 12, 'lengthFrames' => 4, 'restFrame' => 1,
    'stage' => ['canvas' => ['width' => 320, 'height' => 180], 'startFrame' => 0, 'restoreFrame' => 3,
      'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 160, 'y' => 90], 'zoom' => 1]]],
    'tracks' => [['id' => 'body', 'type' => 'image', 'asset' => 'stage.png', 'anchor' => 'stage',
      'sheet' => ['columns' => 2, 'rows' => 1],
      'placement' => ['position' => ['x' => 160, 'y' => 140], 'size' => ['width' => 80, 'height' => 80]],
      'keyframes' => [['frame' => 0, 'duration' => 3, 'sourceFrame' => 1]]]]];
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'synthetic-stage', 'name' => 'Synthetic Stage'], $timeline);
  $compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
  $collision = replacePngWithCollidingFileFacts($this->root . '/stage.png',
    createPaddedPngBytes(16, 8), createPaddedPngBytes(32, 16), fn() => $compiler->compile($definition));
  $before = $collision['primed'];
  $stage = CinematicStage::fromArray($before->defaults['stage'], 4, 1);
  // Draw an already compiled timeline too; current header facts must override its historical crop dimensions.
  $canvas = CinematicStagePresentation::compose($stage->getFrame(1), $before->playbackSegments, $this->root, 640, 360);
  $after = $compiler->compile($definition);
  expect($collision['before'])->toBe($collision['after'])
    ->and($canvas->images[0]->sourceRect->toArray())->toBe(['x' => 16, 'y' => 0, 'width' => 16, 'height' => 16])
    ->and($after->playbackSegments[0]['drawCommands'][0]['payload']['frameWidth'])->toBe(16)
    ->and($after->sourceHash)->toBe($before->sourceHash)->and($after->sourceId)->toBe($before->sourceId)
    ->and($definition->toTimelineArray()['stage'])->toBe($timeline['stage'])
    ->and($after->playbackSegments[0]['drawCommands'][0]['payload']['placement'])
    ->toBe($before->playbackSegments[0]['drawCommands'][0]['payload']['placement']);
});
