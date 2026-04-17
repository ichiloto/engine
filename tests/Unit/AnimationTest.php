<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationTargetPosition;

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
