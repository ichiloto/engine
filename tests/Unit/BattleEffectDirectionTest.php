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

it('turns an opponent-facing ward toward the subject side, wherever its caster stands', function () {
  $ward = ['trackId' => 'ward', 'content' => '(', 'position' => ['x' => -2, 'y' => 0],
    'payload' => ['facing' => 'west', 'toward' => 'opponents']];
  // The party faces west: the caster east or west of its ally changes nothing.
  expect(BattleEffectDirection::orientForSubject($ward, 10, 30, true))->toBe($ward)
    ->and(BattleEffectDirection::orientForSubject($ward, 30, 10, true))->toBe($ward)
    ->and(BattleEffectDirection::orientForSubject($ward, null, null, true))->toBe($ward);
  $troop = BattleEffectDirection::orientForSubject($ward, 30, 10, false);
  expect($troop['content'])->toBe(')')->and($troop['position']['x'])->toBe(2)
    ->and($troop['payload']['flipX'])->toBeTrue()
    // A subject outside both sides keeps its authored facing.
    ->and(BattleEffectDirection::orientForSubject($ward, 10, 30, null))->toBe($ward)
    ->and(BattleEffectDirection::getOpponentsSign(true))->toBe(-1)
    ->and(BattleEffectDirection::getOpponentsSign(false))->toBe(1);
  $stroke = ['trackId' => 'slash', 'payload' => ['facing' => 'west']];
  expect(BattleEffectDirection::orientForSubject($stroke, 10, 30, true)['payload']['flipX'])->toBeTrue()
    ->and(BattleEffectDirection::orientForSubject($stroke, 10, null, true))->toBe($stroke);
});


it('resolves graphical facing from actual ground extents and retains explicit tie orientation', function () {
  $east = \Ichiloto\Engine\Animations\Timelines\EffectHorizontalFacing::EAST;
  $west = \Ichiloto\Engine\Animations\Timelines\EffectHorizontalFacing::WEST;
  expect(BattleEffectDirection::getFacingToward(50, [80, 90, 80], $west))->toBe($east)
    ->and(BattleEffectDirection::getFacingToward(50, [20, 10, 20], $east))->toBe($west)
    ->and(BattleEffectDirection::getFacingToward(50, [90, 80], $west))->toBe($east)
    ->and(BattleEffectDirection::getFacingToward(50, [], $west))->toBe($west)
    ->and(BattleEffectDirection::getFacingToward(50, [20, 80], $east))->toBe($east);
  $grounds = [1 => new \Ichiloto\Engine\Core\Vector2(20, 10), 2 => new \Ichiloto\Engine\Core\Vector2(80, 10),
    3 => new \Ichiloto\Engine\Core\Vector2(90, 20)]; $sides = [1 => true, 2 => false, 3 => false];
  expect(BattleEffectDirection::getOpponentsFacing(1, $grounds, $sides))->toBe($east)
    ->and(BattleEffectDirection::getOpponentsFacing(2, $grounds, $sides))->toBe($west)
    ->and(BattleEffectDirection::getOpponentsFacing(999, $grounds, $sides))->toBeNull()
    ->and(BattleEffectDirection::getOpponentsFacingAt(85, false, $grounds, $sides))->toBe($west);
  $ward = ['trackId' => 'ward', 'payload' => ['facing' => 'west', 'toward' => 'opponents']];
  expect(BattleEffectDirection::orientForFacing($ward, null, null, $east)['payload']['flipX'])->toBeTrue()
    ->and(BattleEffectDirection::orientForFacing($ward, null, null, $west))->toBe($ward)
    ->and(fn() => BattleEffectDirection::getFacingToward(NAN, [], $east))->toThrow(InvalidArgumentException::class)
    ->and(fn() => BattleEffectDirection::getFacingToward(0, [INF], $east))->toThrow(InvalidArgumentException::class);
});
