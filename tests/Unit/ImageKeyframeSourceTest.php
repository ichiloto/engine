<?php

use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineValidator;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = createTestDirectory('image-keyframe-source-');
  writeTestPng($this->root . '/sheet.png', 8, 4);
  writeTestPng($this->root . '/other.png', 12, 8);
  $this->validator = new EffectTimelineValidator($this->root);
  $this->data = ['fps' => 12, 'lengthFrames' => 3, 'restFrame' => 1,
    'tracks' => [['id' => 'motion', 'type' => 'image', 'asset' => 'sheet.png',
      'sheet' => ['columns' => 2, 'rows' => 1], 'fit' => 'contain',
      'keyframes' => [['frame' => 0], ['frame' => 1, 'asset' => 'other.png', 'sourceFrame' => 1],
        ['frame' => 2, 'sourceFrame' => 1]]]]];
});

it('plays separate files on one stable stage track across every cinematic profile', function (string $profile) {
  $keys = [];
  for ($i = 0; $i < 64; $i++) {
    $asset = 'frames/' . $i . '.png';
    writeTestPng($this->root . '/' . $asset, 8, 4);
    $keys[] = ['frame' => $i, 'asset' => $asset];
  }
  $source = ['fps' => 24, 'lengthFrames' => 64, 'restFrame' => 17,
    'stage' => ['canvas' => ['width' => 160, 'height' => 90], 'startFrame' => 0, 'restoreFrame' => 64,
      'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 80, 'y' => 45], 'zoom' => 1]]],
    'tracks' => [['id' => 'motion', 'type' => 'image', 'anchor' => 'stage', 'fit' => 'contain',
      'placement' => ['position' => ['x' => 80, 'y' => 45], 'size' => ['width' => 160, 'height' => 90]],
      'keyframes' => $keys]]];
  $timeline = $profile === 'summon'
    ? (new SummonCutsceneCompiler(assetRoot: $this->root))->compile(
      SummonCutsceneDefinition::fromArrays(['id' => 'separate-frames', 'name' => 'Synthetic sequence'], $source))
    : $this->validator->compile('separate-frames', $source,
      forBattle: $profile === 'battle', forStage: $profile === 'standalone');
  $session = new EffectPlaybackSession($timeline);
  $stage = CinematicStage::fromArray($timeline->defaults['stage'], 64, 17);
  foreach (range(0, 63) as $frame) {
    $session->seek($frame);
    $canvas = CinematicStagePresentation::compose($stage->getFrame($frame),
      $session->getActiveSegments(), $this->root, 320, 180);
    expect($canvas->images)->toHaveCount(1)
      ->and($canvas->images[0]->id)->toBe('cinematic-stage-motion')
      ->and($canvas->images[0]->asset)->toBe('frames/' . $frame . '.png')
      ->and($canvas->images[0]->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 8, 'height' => 4]);
  }
  $rest = $stage->getFrame(63, true);
  $canvas = CinematicStagePresentation::compose($rest,
    $session->getActiveSegments($rest->contentFrame), $this->root, 320, 180);
  expect($canvas->images[0]->asset)->toBe('frames/17.png')
    ->and($session->currentFrame)->toBe(63)->and($session->getCuesAt())->toBe([]);
})->with(['battle', 'summon', 'standalone']);

it('selects sheet cells from each current file and restores the track default in field playback', function () {
  $timeline = $this->validator->compile('mixed-images', $this->data);
  $session = new FieldEffectSession('instance', FieldEffectAnchor::fromArray(['cell' => ['x' => 3, 'y' => 4]]), $timeline);
  foreach ([
    [0, false, 'sheet.png', 0, 4, 4, 48, 48],
    [1, false, 'other.png', 6, 6, 8, 36, 48],
    [2, false, 'sheet.png', 4, 4, 4, 48, 48],
    [2, true, 'other.png', 6, 6, 8, 36, 48],
  ] as [$frame, $reduced, $asset, $x, $width, $height, $drawWidth, $drawHeight]) {
    $session->playback->seek($frame);
    $sprites = $session->getSprites(new Vector2(3, 4), $reduced);
    $sprite = $sprites[0];
    $image = $sprite->getGraphicalSpriteDefinition();
    expect($sprites)->toHaveCount(1)->and($sprite->getGraphicalSpriteId())->toBe('instance:motion')
      ->and($image->asset)->toBe($asset)
      ->and($image->sourceRect->toArray())->toBe(['x' => $x, 'y' => 0, 'width' => $width, 'height' => $height])
      ->and([$image->width, $image->height])->toBe([$drawWidth, $drawHeight])
      ->and($sprite->getGraphicalSpriteWorldPosition())->toEqual(new Vector2(3, 4));
  }
  expect($session->anchor->cell)->toEqual(new Vector2(3, 4));
});

it('reads replacement image dimensions without changing the authored reference or track identity', function () {
  $this->data['tracks'][0]['keyframes'][1]['asset'] = 'sheet.png';
  $timeline = $this->validator->compile('replaceable', $this->data);
  writeTestPng($this->root . '/sheet.png', 20, 6);
  $replacement = $this->validator->compile('replaceable', $this->data);
  $session = new FieldEffectSession('same', FieldEffectAnchor::fromArray(['object' => 'actor']), $replacement);
  $session->playback->seek(1);
  $sprite = $session->getSprites(new Vector2(0, 0))[0];
  expect($replacement->sourceId)->toBe($timeline->sourceId)
    ->and($sprite->getGraphicalSpriteId())->toBe('same:motion')
    ->and($sprite->getGraphicalSpriteDefinition()->asset)->toBe('sheet.png')
    ->and($sprite->getGraphicalSpriteDefinition()->sourceRect->toArray())
      ->toBe(['x' => 10, 'y' => 0, 'width' => 10, 'height' => 6]);
});

it('keeps unavailable graphical sources outside terminal selection', function () {
  $this->data['tracks'][0]['asset'] = '../unavailable.png';
  $this->data['tracks'][0]['keyframes'][1]['asset'] = null;
  $terminal = ['fps' => 5, 'lengthFrames' => 2, 'tracks' => [
    ['id' => 'text', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '*']]]]];
  $paired = ['presentations' => ['terminal' => $terminal, 'graphical' => $this->data]];
  $timeline = $this->validator->compile('paired', $paired, presentation: EffectPresentation::TERMINAL);
  expect($timeline->hasTerminalContent)->toBeTrue()
    ->and(array_column($timeline->playbackSegments, 'layer'))->toBe(['glyph']);
});

it('refuses invalid explicit sources without inheriting an earlier override', function (Closure $change, string $exception) {
  $change($this->data);
  expect(fn() => $this->validator->compile('invalid-image', $this->data))->toThrow($exception);
})->with([
  'missing default and sources' => [static function (array &$data) { unset($data['tracks'][0]['asset']); }, InvalidArgumentException::class],
  'no carry from previous key' => [static function (array &$data) {
    unset($data['tracks'][0]['asset']);
    $data['tracks'][0]['keyframes'][0]['asset'] = 'sheet.png';
  }, InvalidArgumentException::class],
  'explicit null override' => [static function (array &$data) {
    $data['tracks'][0]['keyframes'][1]['asset'] = null;
  }, InvalidArgumentException::class],
  'empty override' => [static function (array &$data) {
    $data['tracks'][0]['keyframes'][1]['asset'] = '';
  }, InvalidArgumentException::class],
  'escaping path' => [static function (array &$data) {
    $data['tracks'][0]['keyframes'][1]['asset'] = '../outside.png';
  }, InvalidArgumentException::class],
  'missing file' => [static function (array &$data) {
    $data['tracks'][0]['keyframes'][1]['asset'] = 'absent.png';
  }, RuntimeException::class],
  'invalid declared default despite overrides' => [static function (array &$data) {
    $data['tracks'][0]['asset'] = null;
    foreach ($data['tracks'][0]['keyframes'] as &$key) { $key['asset'] = 'other.png'; }
  }, InvalidArgumentException::class],
  'override does not divide into grid' => [static function (array &$data) {
    $data['tracks'][0]['sheet'] = ['columns' => 8, 'rows' => 1];
  }, InvalidArgumentException::class],
  'sourceFrame is a sheet cell' => [static function (array &$data) {
    $data['tracks'][0]['keyframes'][1]['sourceFrame'] = 2;
  }, InvalidArgumentException::class],
]);

it('preserves track and keyframe bounds for separate-file sequences', function () {
  $track = $this->data['tracks'][0];
  $this->data['tracks'] = array_map(static fn(int $i): array => [...$track, 'id' => 'track-' . $i], range(0, 32));
  expect(fn() => $this->validator->compile('track-bound', $this->data))
    ->toThrow(InvalidArgumentException::class, '1..32 tracks');
  $track['keyframes'] = array_fill(0, 10001, ['frame' => 0, 'asset' => 'sheet.png']);
  $this->data['tracks'] = [$track];
  expect(fn() => $this->validator->compile('key-bound', $this->data))
    ->toThrow(InvalidArgumentException::class, 'list of keyframes');
});
