<?php

use Ichiloto\Engine\Battle\EnemyActionEvaluator;
use Ichiloto\Engine\Core\Range;
use Ichiloto\Engine\Entities\Enemies\ActionCondition;
use Ichiloto\Engine\Entities\Enemies\ActionPattern;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\States\HasStates;
use Ichiloto\Engine\Entities\Stats;

function makeAiEnemy(int $hp = 100, int $totalHp = 100, int $mp = 0): object
{
  return new class($hp, $totalHp, $mp) {
    use HasStates;

    public Stats $stats;
    public bool $isKnockedOut {
      get {
        return $this->stats->currentHp <= 0;
      }
    }

    public function __construct(int $hp, int $totalHp, int $mp)
    {
      $this->stats = new Stats(currentHp: $hp, totalHp: $totalHp, currentMp: $mp, totalMp: max(1, $mp));
    }
  };
}

function makePattern(int $rating, ActionCondition $condition, int $cost = 0): ActionPattern
{
  $skill = new BasicSkill(
    'Test Move',
    '',
    '',
    $cost,
    0,
    new ItemScope(),
    Occasion::BATTLE_SCREEN,
    new SkillInvocation()
  );

  return new ActionPattern($skill, $rating, $condition);
}

it('always-conditions always hold', function () {
  $patterns = [makePattern(5, new ActionCondition())];

  expect(EnemyActionEvaluator::filterUsablePatterns($patterns, makeAiEnemy(), 1, 1))->toHaveCount(1);
});

it('gates on turn number with and without an interval', function () {
  $everyThirdFromTwo = new ActionCondition(ActionConditionType::TURN, a: 2, b: 3);
  $onlyRoundFour = new ActionCondition(ActionConditionType::TURN, a: 4);
  $enemy = makeAiEnemy();

  expect(EnemyActionEvaluator::conditionHolds($everyThirdFromTwo, $enemy, 2, 1))->toBeTrue()
    ->and(EnemyActionEvaluator::conditionHolds($everyThirdFromTwo, $enemy, 3, 1))->toBeFalse()
    ->and(EnemyActionEvaluator::conditionHolds($everyThirdFromTwo, $enemy, 5, 1))->toBeTrue()
    ->and(EnemyActionEvaluator::conditionHolds($onlyRoundFour, $enemy, 4, 1))->toBeTrue()
    ->and(EnemyActionEvaluator::conditionHolds($onlyRoundFour, $enemy, 5, 1))->toBeFalse();
});

it('gates on the enemy HP percentage', function () {
  $belowHalf = new ActionCondition(ActionConditionType::HP, new Range(0, 50));

  expect(EnemyActionEvaluator::conditionHolds($belowHalf, makeAiEnemy(30, 100), 1, 1))->toBeTrue()
    ->and(EnemyActionEvaluator::conditionHolds($belowHalf, makeAiEnemy(80, 100), 1, 1))->toBeFalse();
});

it('gates on afflicted states and party level', function () {
  $whilePoisoned = new ActionCondition(ActionConditionType::Status, status: 'poison');
  $enemy = makeAiEnemy();

  expect(EnemyActionEvaluator::conditionHolds($whilePoisoned, $enemy, 1, 1))->toBeFalse();

  $enemy->addState(Ichiloto\Engine\Entities\States\State::fromArray(['id' => 'poison', 'name' => 'Poison']));
  expect(EnemyActionEvaluator::conditionHolds($whilePoisoned, $enemy, 1, 1))->toBeTrue();

  $highLevelParty = new ActionCondition(ActionConditionType::PARTY_LEVEL, partyLevel: 10);
  expect(EnemyActionEvaluator::conditionHolds($highLevelParty, $enemy, 1, 9))->toBeFalse()
    ->and(EnemyActionEvaluator::conditionHolds($highLevelParty, $enemy, 1, 10))->toBeTrue();
});

it('drops patterns the enemy cannot afford', function () {
  $patterns = [makePattern(5, new ActionCondition(), cost: 10)];

  expect(EnemyActionEvaluator::filterUsablePatterns($patterns, makeAiEnemy(mp: 4), 1, 1))->toHaveCount(0)
    ->and(EnemyActionEvaluator::filterUsablePatterns($patterns, makeAiEnemy(mp: 12), 1, 1))->toHaveCount(1);
});

it('keeps only patterns rated within two of the best', function () {
  $weak = makePattern(1, new ActionCondition());
  $strong = makePattern(9, new ActionCondition());

  // Rating 1 sits below the 9-3 floor, so the strong pattern always wins.
  for ($i = 0; $i < 25; $i++) {
    expect(EnemyActionEvaluator::pickPattern([$weak, $strong]))->toBe($strong);
  }

  expect(EnemyActionEvaluator::pickPattern([]))->toBeNull();
});
