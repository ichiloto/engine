<?php

use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\BattleCommandType;
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
    ->and($option->label)->toBe('Fireball (12 MP)')
    ->and($option->type)->toBe(BattleCommandType::ATTACK);
});

it('removes legacy icon prefixes without stripping words from authored skill names', function () {
  $skill = new BasicSkill('ATK Training Strike', 'Authored name.', 'ATK', 0, 1);
  $option = new ReflectionMethod(BattleCommandCatalog::class, 'createSkillOption')->invoke(null, $skill);
  expect($option->label)->toBe('ATK Training Strike')
    ->and($option->action->name)->toBe($skill->name)
    ->and($option->source)->toBe($skill)
    ->and($option->type->getIconRole())->toBe('command.attack');
});

it('never reports a negative MP cost', function () {
  $method = new ReflectionMethod(BattleCommandCatalog::class, 'createSkillOption');
  $option = $method->invoke(null, makeCostedSkill(-5));

  expect($option->mpCost)->toBe(0);
});
