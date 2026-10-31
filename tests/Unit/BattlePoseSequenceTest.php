<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSequence;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

it('keeps historical pose calls on main and omitted transitions empty without changing source metadata', function () {
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4,
    frames: [2, 0, 3], fps: 5);
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  expect($pose->getSequenceFrames(BattlePoseSequence::MAIN))->toBe([2, 0, 3])
    ->and($pose->getSequenceSeconds(BattlePoseSequence::MAIN))->toBe(.6);
  foreach ([BattlePoseSequence::ENTRY, BattlePoseSequence::EXIT] as $sequence) {
    expect($pose->getSequenceFrames($sequence))->toBe([])
      ->and($pose->getSequenceSeconds($sequence))->toBe(0.0)
      ->and($pose->getArtwork($root, 0, sequence: $sequence))->toBeNull();
  }
  foreach ([0.0, .2, .4, .6, 100.0] as $elapsed) {
    expect($pose->getArtwork($root, $elapsed))->toEqual(
      $pose->getArtwork($root, $elapsed, sequence: BattlePoseSequence::MAIN));
  }
  $snapshot = $pose->getSequenceFrames(BattlePoseSequence::MAIN);
  $snapshot[0] = 99;
  expect($pose->frames)->toBe([2, 0, 3]);
});

it('plays transition holds once at exact own-clock boundaries independently of looping and movement duration',
  function (BattlePoseSequence $sequence, int $fps) {
    $root = dirname(__DIR__) . '/Fixtures/Renderer';
    $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, rows: 2,
      frames: [0, 1], fps: $fps, loop: true, restFrame: 2,
      entryFrames: [6, 6, 3], exitFrames: [4, 7, 4]);
    $frames = $pose->getSequenceFrames($sequence);
    expect($pose->getSequenceSeconds($sequence))->toBe(3.0 / $fps);
    foreach ([0.0, 1.0 - 1e-8, 1.0, 2.0 - 1e-8, 2.0, 3.0, 500.0] as $tick) {
      $source = $frames[min((int)floor($tick), 2)];
      $art = $pose->getArtwork($root, $tick / $fps, impactAtSeconds: 50,
        durationSeconds: .01, sequence: $sequence);
      expect([$art->sourceRect->x, $art->sourceRect->y, $art->width, $art->height])
        ->toBe([($source % 4) * 80, intdiv($source, 4) * 90, 80, 90]);
    }
    expect($pose->getArtwork($root, 100, true, sequence: $sequence)->sourceRect->x)->toBe(160)
      ->and($pose->getArtwork($root, 100, true, sequence: $sequence)->sourceRect->y)->toBe(0)
      ->and($pose->getArtwork($root, 2 / $fps)->sourceRect->x)->toBe(0);
  })->with([BattlePoseSequence::ENTRY, BattlePoseSequence::EXIT])->with([1, 8, 12, 120]);

it('does not reuse an action impact anchor for transition playback or stretch transition cells over a return hop', function () {
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4,
    frames: [0, 1, 2, 3], fps: 10, loop: false, impactFrame: 2, entryFrames: [3, 1], exitFrames: [2, 0]);
  foreach ([.01, 20.0] as $duration) {
    expect($pose->getArtwork($root, .1, impactAtSeconds: 10, durationSeconds: $duration,
      sequence: BattlePoseSequence::ENTRY)->sourceRect->x)->toBe(80)
      ->and($pose->getArtwork($root, .1, impactAtSeconds: 10, durationSeconds: $duration,
        sequence: BattlePoseSequence::EXIT)->sourceRect->x)->toBe(0);
  }
  expect($pose->getArtwork($root, .1, impactAtSeconds: 10)->sourceRect->x)->toBe(80)
    ->and($pose->getArtwork($root, 10, impactAtSeconds: 10)->sourceRect->x)->toBe(160);
});

it('keeps finite timing validation for every sequence including omitted clips', function (BattlePoseSequence $sequence) {
  $pose = new BattlerPose('missing.png');
  foreach ([-1.0, INF, NAN] as $elapsed) {
    expect(fn() => $pose->getArtwork('', $elapsed, sequence: $sequence))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => $pose->getArtwork('', 0, impactAtSeconds: INF, sequence: $sequence))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => $pose->getArtwork('', 0, durationSeconds: 0, sequence: $sequence))
    ->toThrow(InvalidArgumentException::class);
})->with(BattlePoseSequence::cases());

it('validates transition source lists against the shared sheet and existing per-sequence resource bound', function (string $field, array $frames) {
  expect(fn() => new BattlerPose('pose.png', ...['columns' => 2, $field => $frames]))
    ->toThrow(InvalidArgumentException::class, $field);
})->with(['entryFrames', 'exitFrames'])->with([
  'non-list' => [[1 => 0]], 'string' => [['0']], 'float' => [[0.0]],
  'boolean' => [[false]], 'negative' => [[-1]], 'outside sheet' => [[2]],
  'over budget' => [array_fill(0, 10001, 0)],
]);

it('permits optional transition clips only on the supported movement lifecycle roles', function () {
  foreach (BattlePoseRole::cases() as $role) {
    foreach (['entryFrames', 'exitFrames'] as $field) {
      $pose = new BattlerPose('pose.png', ...['columns' => 2, $field => [1, 0]]);
      if (in_array($role, [BattlePoseRole::MOVE, BattlePoseRole::RETURNING], true)) {
        expect((new BattlePoseSet([$role->value => $pose]))->getPose($role))->toBe($pose);
      } else {
        expect(fn() => new BattlePoseSet([$role->value => $pose]))
          ->toThrow(InvalidArgumentException::class, 'entry/exit');
      }
    }
    expect((new BattlePoseSet([$role->value => new BattlerPose('pose.png')]))->getPose($role))->not->toBeNull();
  }
  expect(fn() => new BattlePoseSet(['move' => new BattlerPose('pose.png', loop: false, entryFrames: [0])]))
    ->toThrow(InvalidArgumentException::class, 'must loop');
  foreach (['move', 'return'] as $role) {
    expect(fn() => new BattlePoseSet([$role => new BattlerPose('pose.png', loop: false,
      impactFrame: 0, exitFrames: [0])]))->toThrow(InvalidArgumentException::class, 'impact anchor');
  }
});

it('accepts the bounded sheet edge and maximum authored holds without copying presentation resources', function () {
  $pose = new BattlerPose('pose.png', columns: 64, rows: 64, frames: [4095], fps: 120,
    entryFrames: array_fill(0, 10000, 4095), exitFrames: [0]);
  expect($pose->getSequenceFrames(BattlePoseSequence::ENTRY))->toHaveCount(10000)
    ->and($pose->getSequenceSeconds(BattlePoseSequence::ENTRY))->toBe(10000.0 / 120)
    ->and($pose->asset)->toBe('pose.png');
});

it('hydrates movement transitions for either side through the current binding and catalog contract without rewriting input', function () {
  $entry = ['poses' => [
    'move' => ['image' => 'pose.png', 'columns' => 4, 'frames' => [1, 2], 'fps' => 12,
      'entryFrames' => [0, 0, 1], 'exitFrames' => [3, 2]],
    'return' => ['image' => 'pose.png', 'columns' => 4, 'frames' => [2, 1], 'loop' => false,
      'fps' => 8, 'entryFrames' => [3, 3], 'exitFrames' => [1, 0]],
  ]];
  $data = ['actors' => ['actor' => $entry], 'enemies' => ['enemy' => $entry]];
  $before = $data;
  $catalog = (new BattlePresentationCatalog([], [], []))->bindBattlers(BattlerBindings::getFromArray($data, ''));
  foreach ([$catalog->actorPoses['actor'], $catalog->enemyPoses['enemy']] as $set) {
    foreach ([BattlePoseRole::MOVE, BattlePoseRole::RETURNING] as $role) {
      $pose = $set->getPose($role);
      expect($pose->entryFrames)->toBe($entry['poses'][$role->value]['entryFrames'])
        ->and($pose->exitFrames)->toBe($entry['poses'][$role->value]['exitFrames'])
        ->and($pose->asset)->toBe('pose.png');
    }
  }
  expect($data)->toBe($before);
});

it('refuses explicit malformed transition data at the authored binding boundary', function (string $field, mixed $frames) {
  $pose = ['image' => 'pose.png', 'columns' => 2, $field => $frames];
  expect(fn() => BattlerBindings::getFromArray(['actors' => ['actor' => ['poses' => ['move' => $pose]]]], ''))
    ->toThrow(InvalidArgumentException::class, $field);
})->with(['entryFrames', 'exitFrames'])->with([
  'null' => [null], 'scalar' => ['0,1'], 'boolean' => [false], 'map' => [['cell' => 0]],
  'string cell' => [['0']], 'float cell' => [[0.0]], 'boolean cell' => [[false]],
  'negative cell' => [[-1]], 'outside sheet' => [[2]], 'over budget' => [array_fill(0, 10001, 0)],
]);

it('refuses unsupported authored role transitions before any artwork is accessed', function (string $field) {
  expect(fn() => BattlerBindings::getFromArray(['enemies' => ['enemy' => ['poses' => [
    'idle' => ['image' => 'missing.png', $field => [0]],
  ]]]], ''))->toThrow(InvalidArgumentException::class, 'entry/exit');
})->with(['entryFrames', 'exitFrames']);

it('keeps empty authored transition lists optional and reads replacement sheet dimensions from the current resource', function () {
  $root = createTestDirectory('ichiloto-pose-sequences-');
  $data = ['actors' => ['actor' => ['poses' => ['return' => [
    'image' => 'pose.png', 'columns' => 2, 'frames' => [0], 'loop' => false,
    'entryFrames' => [], 'exitFrames' => [1],
  ]]]]];
  $pose = BattlerBindings::getFromArray($data, $root)->actorPoses['actor']->getPose(BattlePoseRole::RETURNING);
  expect($pose->getArtwork($root, 0, sequence: BattlePoseSequence::ENTRY))->toBeNull();
  foreach ([[40, 20], [80, 40]] as [$width, $height]) {
    writeTestPng($root . '/pose.png', $width, $height);
    $art = $pose->getArtwork($root, 0, sequence: BattlePoseSequence::EXIT);
    expect([$art->width, $art->height, $art->sourceRect->x])->toBe([intdiv($width, 2), $height, intdiv($width, 2)]);
  }
});
