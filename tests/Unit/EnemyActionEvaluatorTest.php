<?php

use Ichiloto\Engine\Battle\EnemyActionEvaluator;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Core\Range;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enemies\ActionCondition;
use Ichiloto\Engine\Entities\Enemies\ActionPattern;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\States\HasStates;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;

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

function makePatternEnemy(ActionPattern ...$patterns): Enemy
{
  $enemy = (new ReflectionClass(Enemy::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty(Enemy::class, 'stats'))->setValue(
    $enemy,
    new Stats(currentHp: 100, totalHp: 100, currentMp: 100, totalMp: 100),
  );
  (new ReflectionProperty(Enemy::class, 'actionPatterns'))->setValue($enemy, $patterns);

  return $enemy;
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

it('passes world switches through action selection', function () {
  $pattern = makePattern(5, new ActionCondition(ActionConditionType::SWITCH, status: 'boss_enraged'));
  $enemy = makePatternEnemy($pattern);

  [$enabledAction] = EnemyActionEvaluator::chooseAction(
    $enemy,
    [$enemy],
    [$enemy],
    1,
    1,
    static fn(string $switch): bool => $switch === 'boss_enraged',
  );
  [$disabledAction] = EnemyActionEvaluator::chooseAction(
    $enemy,
    [$enemy],
    [$enemy],
    1,
    1,
    static fn(string $switch): bool => false,
  );

  expect($enabledAction)->toBeInstanceOf(SkillBattleAction::class)
    ->and($disabledAction)->toBeInstanceOf(AttackAction::class);
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

it('lets an enemy target its own fallen ally without substituting a living player', function () {
  $scope = new ItemScope(ItemScopeSide::ALLY, status: ItemScopeStatus::DEAD);
  $skill = new BasicSkill('Synthetic revive', '', '', 0, 0, $scope);
  $enemy = makePatternEnemy(new ActionPattern($skill, 5, new ActionCondition()));
  $fallen = makePatternEnemy();
  $fallen->stats->currentHp = 0;
  $player = new Character('Player', 1, new Stats(currentHp: 100));
  [$action, $targets] = EnemyActionEvaluator::chooseAction($enemy, [$player], [$enemy, $fallen], 1, 1);
  expect($action)->toBeInstanceOf(SkillBattleAction::class)
    ->and(array_map(spl_object_id(...), $targets))->toBe([spl_object_id($fallen)]);
});

it('resolves authored random enemy skills to their count and status', function () {
  $scope = new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::RANDOM, ItemScopeStatus::ALIVE, 2);
  $skill = new BasicSkill('Synthetic random', '', '', 0, 0, $scope);
  $enemy = makePatternEnemy(new ActionPattern($skill, 5, new ActionCondition()));
  $players = array_map(static fn(int $hp): Character => new Character('Player', 1,
    new Stats(currentHp: $hp)), [100, 100, 0]);
  [$action, $targets] = EnemyActionEvaluator::chooseAction($enemy, $players, [$enemy], 1, 1);
  expect($action)->toBeInstanceOf(SkillBattleAction::class)
    ->and(array_map(spl_object_id(...), $targets))
    ->toEqualCanonicalizing([spl_object_id($players[0]), spl_object_id($players[1])]);
});

it('does not substitute self when the opposing side has no eligible recipients', function () {
  $enemy = makePatternEnemy(makePattern(5, new ActionCondition()));
  $fallen = new Character('Fallen', 1, new Stats(currentHp: 0));
  [$action, $targets] = EnemyActionEvaluator::chooseAction($enemy, [$fallen], [$enemy], 1, 1);
  expect($action)->toBeInstanceOf(AttackAction::class)->and($targets)->toBe([]);
});
