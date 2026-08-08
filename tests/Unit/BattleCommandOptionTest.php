<?php

use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;

function makeCostedSkill(int $cost): BasicSkill
{
  return new BasicSkill(
    'Fireball',
    'Hurls a ball of fire.',
    '🔥',
    $cost,
    1,
    new ItemScope(),
    Occasion::ALWAYS,
    new SkillInvocation('%1 casts Fireball on %2.', 0, 100, 1)
  );
}

it('carries the skill MP cost onto the battle option', function () {
  $method = new ReflectionMethod(BattleCommandCatalog::class, 'createSkillOption');
  $option = $method->invoke(null, makeCostedSkill(12));

  expect($option)->toBeInstanceOf(BattleCommandOption::class)
    ->and($option->mpCost)->toBe(12)
    ->and($option->label)->toContain('(12 MP)');
});

it('never reports a negative MP cost', function () {
  $method = new ReflectionMethod(BattleCommandCatalog::class, 'createSkillOption');
  $option = $method->invoke(null, makeCostedSkill(-5));

  expect($option->mpCost)->toBe(0);
});
