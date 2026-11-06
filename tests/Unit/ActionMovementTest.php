<?php

use Ichiloto\Engine\Animations\{ActionAnimationResolver, ActionMovement, Animation, AnimationLibrary};
use Ichiloto\Engine\Battle\Actions\{AttackAction, SkillBattleAction};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

it('keeps the historical step default and round trips explicit target movement', function () {
  $old = Animation::fromArray(['id' => 1, 'name' => 'Shared effect']);
  expect($old->movement)->toBe(ActionMovement::STEP)->and($old->toArray())->not->toHaveKey('movement');
  $run = Animation::fromArray([...$old->toArray(), 'movement' => 'run-to-target']);
  expect($run->movement)->toBe(ActionMovement::RUN_TO_TARGET)
    ->and(Animation::fromArray($run->toArray())->movement)->toBe(ActionMovement::RUN_TO_TARGET)
    ->and((clone $run)->movement)->toBe(ActionMovement::RUN_TO_TARGET);
});

it('rejects unknown or incorrectly typed movement definitions', function (mixed $value) {
  expect(fn() => Animation::fromArray(['id' => 1, 'movement' => $value]))->toThrow(InvalidArgumentException::class);
})->with(['run', '', 'RUN_TO_TARGET', false, 1, null]);

it('round trips action-owned flash step timing and rejects incompatible timing', function () {
  $timing = ['fps' => 24, 'guardFrames' => 20, 'advanceFrames' => 3, 'holdFrames' => 10,
    'returnFrames' => 3, 'settleFrames' => 7, 'echoes' => 2];
  $animation = Animation::fromArray(['id' => 1, 'movement' => 'flash-step', 'flashStep' => $timing]);
  expect($animation->movement->usesTargetRoute())->toBeTrue()
    ->and(Animation::fromArray($animation->toArray())->flashStep?->toArray())->toBe($timing)
    ->and(fn() => Animation::fromArray(['id' => 1, 'movement' => 'flash-step']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Animation::fromArray(['id' => 1, 'flashStep' => $timing]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Animation::fromArray(['id' => 1, 'movement' => 'flash-step',
      'flashStep' => [...$timing, 'echoes' => 4]]))->toThrow(InvalidArgumentException::class);
});

it('selects distinct movement bindings for bare Attack and explicit skills while sharing their effect resources', function () {
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $step = new Animation(1, 'Shared timeline from range', targetEffect: 'shared-hit');
  $run = new Animation(2, 'Shared timeline at contact', targetEffect: 'shared-hit', roles: ['attack-unarmed'], movement: ActionMovement::RUN_TO_TARGET);
  $library = AnimationLibrary::createFromAnimations([$step, $run]);
  $attack = ActionAnimationResolver::resolveForAction(new AttackAction('Renamed'), $actor, $library);
  $skill = ActionAnimationResolver::resolveForAction(new SkillBattleAction(new SpecialSkill('Renamed', '', '', 0, 0, animationId: 1)), $actor, $library);
  expect($attack->movement)->toBe(ActionMovement::RUN_TO_TARGET)->and($skill->movement)->toBe(ActionMovement::STEP)
    ->and($attack->targetEffect)->toBe($skill->targetEffect);
});


it('preserves the step default in historical serialized animation objects', function () {
  $class = Animation::class;
  $old = unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));
  expect($old->movement)->toBe(ActionMovement::STEP);
});

it('validates authored scope and shape before run travel regardless of current target count', function () {
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Other', 1, new Stats(currentHp: 100, totalHp: 100));
  foreach ([
    new SpecialSkill('Area', '', '', 0, 0, scope: new \Ichiloto\Engine\Entities\ItemScope(number: \Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber::ALL)),
    new SpecialSkill('Line', '', '', 0, 0, occasion: \Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN, battleTargeting: new \Ichiloto\Engine\Battle\Formation\BattleTargeting(\Ichiloto\Engine\Battle\Formation\BattleTargetShape::LINE)),
    new \Ichiloto\Engine\Entities\Skills\MagicSkill('Magic', '', '', 0, 0),
  ] as $skill) {
    expect(fn() => ActionMovement::RUN_TO_TARGET->validateAction(new SkillBattleAction($skill), $actor, [$target]))
      ->toThrow(InvalidArgumentException::class);
  }
  foreach ([\Ichiloto\Engine\Entities\Enumerations\WeaponType::BOW, \Ichiloto\Engine\Entities\Enumerations\WeaponType::CROSSBOW, \Ichiloto\Engine\Entities\Enumerations\WeaponType::GUN] as $weapon) {
    $ranged = new Character('Ranged', 1, new Stats(currentHp: 100, totalHp: 100), attackStyle: $weapon);
    expect(fn() => ActionMovement::RUN_TO_TARGET->validateAction(new AttackAction('Attack'), $ranged, [$target]))
      ->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => ActionMovement::RUN_TO_TARGET->validateAction(new AttackAction('Attack'), $actor, [$actor]))->toThrow(InvalidArgumentException::class);
});


it('keeps magic on step when a crisis wrapper presents it under the Attack command', function () {
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Other', 1, new Stats(currentHp: 100, totalHp: 100));
  $ability = \Ichiloto\Engine\Battle\CrisisAbility::fromArray([
    'name' => 'Synthetic', 'hpBelowPercent' => 10, 'variants' => [['skill' => 'Synthetic magic']],
  ]);
  $action = new \Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction(
    new \Ichiloto\Engine\Entities\Skills\MagicSkill('Synthetic magic', '', '', 0, 0), $ability, 'Synthetic');
  expect(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::getForAction($action))->toBe(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK);
  expect(fn() => ActionMovement::RUN_TO_TARGET->validateAction($action, $actor, [$target]))->toThrow(InvalidArgumentException::class);
});
