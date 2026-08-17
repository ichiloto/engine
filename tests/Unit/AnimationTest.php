<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Animations\AnimationPlaybackSession;
use Ichiloto\Engine\Animations\AnimationPlayer;

it('hydrates animations from arrays and preserves frame cells and cues', function () {
  $animation = Animation::fromArray([
    'id' => 12,
    'name' => 'Hit Spark',
    'position' => 'center',
    'maxFrames' => 3,
    'frames' => [
      [
        'index' => 1,
        'cells' => [
          ['symbol' => '*', 'x' => -1, 'y' => 0, 'color' => 'yellow'],
        ],
      ],
    ],
    'cues' => [
      ['frame' => 1, 'soundEffect' => 'Blow3', 'flashColor' => 'white', 'flashDurationFrames' => 2],
    ],
  ]);

  expect($animation->id)->toBe(12)
    ->and($animation->name)->toBe('Hit Spark')
    ->and($animation->position)->toBe(AnimationTargetPosition::CENTER)
    ->and($animation->maxFrames)->toBe(3)
    ->and($animation->getFrame(1)->getCellAt(-1, 0)?->symbol)->toBe('*')
    ->and($animation->getCue(1)?->soundEffect)->toBe('Blow3')
    ->and($animation->getCue(1)?->flashColor)->toBe('white');
});

it('adds and removes cells and cues through the runtime model', function () {
  $animation = new Animation(1, 'Test Animation', maxFrames: 2);

  $animation->setCell(1, 0, 0, '*', 'red');
  $animation->setCue(1, new AnimationCue(soundEffect: 'Spark', flashColor: 'red', flashDurationFrames: 1));
  $animation->setCell(1, 0, 0, ' ');
  $animation->setCue(1, new AnimationCue());

  expect($animation->getFrame(1)->getCellAt(0, 0))->toBeNull()
    ->and($animation->getCue(1))->toBeNull();
});

it('advances reusable animations non-blockingly across every elapsed frame', function () {
  $animation = new Animation(2, 'Non-blocking Animation', maxFrames: 4);
  $session = new AnimationPlaybackSession($animation, 0.1);

  expect($session->update(0.05))->toBe([])
    ->and($session->currentFrame)->toBe(1)
    ->and($session->update(0.25))->toBe([2, 3, 4])
    ->and($session->currentFrame)->toBe(4)
    ->and($session->isComplete)->toBeFalse();

  expect($session->update(0.1))->toBe([])
    ->and($session->isComplete)->toBeTrue();
});

it('keeps the blocking animation player compatible with shared traversal', function () {
  $animation = new Animation(3, 'Blocking Animation', maxFrames: 3);
  $rendered = [];

  (new AnimationPlayer(0.01))->play(
    $animation,
    function (int $frameIndex) use (&$rendered): void {
      $rendered[] = $frameIndex;
    },
  );

  expect($rendered)->toBe([1, 2, 3]);
});
