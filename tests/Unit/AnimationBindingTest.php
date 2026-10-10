<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationTargetPosition;

it('round trips independent source and target effect bindings with semantic roles and legacy frames', function () {
  $animation = new Animation(51, 'Replaceable title', AnimationTargetPosition::HEAD, maxFrames: 3,
    sourceEffect: 'battle-prepare', targetEffect: 'battle-cross-slash', roles: ['attack', 'skill']);
  $animation->setCell(2, 1, -1, '/', 'LIGHT_BLUE');
  $animation->setCue(2, new AnimationCue(soundEffect: 'impact'));
  $data = $animation->toArray();
  $restored = Animation::fromArray($data);
  expect($data['sourceEffect'])->toBe('battle-prepare')->and($data['targetEffect'])->toBe('battle-cross-slash')
    ->and($data['roles'])->toBe(['attack', 'skill'])->and($restored->toArray())->toBe($data)
    ->and($restored->sourceEffect)->toBe($animation->sourceEffect)->and($restored->targetEffect)->toBe($animation->targetEffect)
    ->and($restored->position)->toBe(AnimationTargetPosition::HEAD)->and($restored->maxFrames)->toBe(3)
    ->and($restored->getFrame(2)->getCells())->toHaveCount(1)->and($restored->getCue(2)?->soundEffect)->toBe('impact');
});

it('does not invent bindings or roles for a legacy animation', function () {
  $animation = Animation::fromArray(['id' => 51, 'name' => 'Legacy']);
  expect($animation->sourceEffect)->toBeNull()->and($animation->targetEffect)->toBeNull()->and($animation->roles)->toBeEmpty()
    ->and($animation->toArray())->not->toHaveKeys(['sourceEffect', 'targetEffect', 'roles']);
});

it('refuses unsafe effect references during both construction and hydration', function (string $invalid) {
  expect(fn() => new Animation(51, 'Unsafe', sourceEffect: $invalid))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Animation::fromArray(['id' => 51, 'targetEffect' => $invalid]))->toThrow(InvalidArgumentException::class);
})->with(['../escape', '/absolute', 'battle/../escape', 'bad\\path', '']);
