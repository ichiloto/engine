<?php

use Ichiloto\Engine\Battle\Presentation\BattleEffectDirection;

it('orients image strokes, glyphs and offsets without changing time, crop or authored reverse stroke', function () {
  $command = ['trackId' => 'slash', 'content' => '/', 'position' => ['x' => 2, 'y' => -1],
    'payload' => ['facing' => 'east', 'sourceFrame' => 3, 'flipX' => true, 'flipY' => true]];
  expect(BattleEffectDirection::orientCommand($command, 10, 30))->toBe($command)
    ->and(BattleEffectDirection::orientCommand($command, 10, 10))->toBe($command);
  $west = BattleEffectDirection::orientCommand($command, 30, 10);
  expect($west['content'])->toBe('\\')->and($west['position'])->toBe(['x' => -2, 'y' => -1])
    ->and($west['payload'])->toBe(['facing' => 'east', 'sourceFrame' => 3, 'flipX' => false, 'flipY' => true])
    ->and($command['content'])->toBe('/');
  unset($command['payload']['facing']);
  expect(BattleEffectDirection::orientCommand($command, 30, 10))->toBe($command);
});
