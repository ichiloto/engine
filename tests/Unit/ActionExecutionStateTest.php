<?php

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Enumerations\Color;

it('builds floating damage and knockout popup lines for defeated targets', function () {
  $state = makeActionExecutionStateForTest();
  $target = new Character('Liora', 0, new Stats(currentHp: 0, totalHp: 100, currentMp: 12, totalMp: 20));

  $lines = invokeActionExecutionPopupBuilder($state, $target, 48, 12);

  expect($lines)->toBe([
    ['text' => '48', 'color' => Color::LIGHT_RED],
    ['text' => 'KO', 'color' => Color::YELLOW],
  ]);
});

it('builds a miss popup when no visible stat changes occur', function () {
  $state = makeActionExecutionStateForTest();
  $target = new Character('Kaelion', 0, new Stats(currentHp: 75, totalHp: 100, currentMp: 18, totalMp: 20));

  $lines = invokeActionExecutionPopupBuilder($state, $target, 75, 18);

  expect($lines)->toBe([
    ['text' => 'MISS', 'color' => Color::WHITE],
  ]);
});

it('maps restorative magic to a green cast effect', function () {
  $state = makeActionExecutionStateForTest();
  $skill = new MagicSkill(
    'Cure',
    'Restores HP.',
    '*',
    3,
    0,
    new ItemScope(),
    Occasion::ALWAYS,
    effects: [
      new HPRecoverSkillEffect('20'),
    ],
    effectType: MagicEffectType::RESTORATIVE,
  );

  $color = invokeMagicCastEffectColorResolver($state, new SkillBattleAction($skill));

  expect($color)->toBe(Color::GREEN);
});

/**
 * Creates a lightweight action execution state for popup-line tests.
 *
 * @return ActionExecutionState
 */
function makeActionExecutionStateForTest(): ActionExecutionState
{
  return (new ReflectionClass(ActionExecutionState::class))->newInstanceWithoutConstructor();
}

/**
 * Invokes the protected popup builder on the action execution state.
 *
 * @param ActionExecutionState $state The action execution state under test.
 * @param Character $target The target to inspect.
 * @param int $previousHp The target HP before the action.
 * @param int $previousMp The target MP before the action.
 * @return array<int, array{text: string, color: Color}>
 */
function invokeActionExecutionPopupBuilder(
  ActionExecutionState $state,
  Character $target,
  int $previousHp,
  int $previousMp
): array
{
  $method = new ReflectionMethod(ActionExecutionState::class, 'buildStatChangePopupLines');

  return $method->invoke($state, $target, $previousHp, $previousMp);
}

/**
 * Invokes the protected magic effect color resolver on the action execution state.
 *
 * @param ActionExecutionState $state The action execution state under test.
 * @param SkillBattleAction $action The magic battle action.
 * @return Color
 */
function invokeMagicCastEffectColorResolver(ActionExecutionState $state, SkillBattleAction $action): Color
{
  $method = new ReflectionMethod(ActionExecutionState::class, 'resolveMagicCastEffectColor');

  return $method->invoke($state, $action->skill);
}
