<?php

use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope as InventoryScope;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;

final class TargetPolicyRandom implements CombatRandomSource
{
  public int $calls = 0;

  public function nextInt(int $minimum, int $maximum): int
  {
    $this->calls++;
    return $maximum;
  }
}

function createPolicyTargets(): array
{
  return array_map(static fn(int $hp): Character => new Character('Synthetic', 1,
    new Stats(currentHp: $hp, totalHp: 100)), [100, 100, 0, 100, 0]);
}

it('uses the actor-relative side and authored alive/dead/any status',
  function (ItemScopeSide $side, ItemScopeStatus $status, array $expected) {
    $targets = createPolicyTargets();
    $resolved = BattleTargetPolicy::getEligibleTargets(new ItemScope($side, status: $status),
      $targets[0], array_slice($targets, 0, 3), array_slice($targets, 3));
    expect(array_map(spl_object_id(...), $resolved))
      ->toBe(array_map(static fn(int $index): int => spl_object_id($targets[$index]), $expected));
  })->with([
    'living own side' => [ItemScopeSide::ALLY, ItemScopeStatus::ALIVE, [0, 1]],
    'fallen own side' => [ItemScopeSide::ALLY, ItemScopeStatus::DEAD, [2]],
    'any own side' => [ItemScopeSide::ALLY, ItemScopeStatus::ANY, [0, 1, 2]],
    'living opponents' => [ItemScopeSide::ENEMY, ItemScopeStatus::ALIVE, [3]],
    'fallen opponents' => [ItemScopeSide::ENEMY, ItemScopeStatus::DEAD, [4]],
    'any opponents' => [ItemScopeSide::ENEMY, ItemScopeStatus::ANY, [3, 4]],
    'living either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeStatus::ALIVE, [0, 1, 3]],
    'fallen either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeStatus::DEAD, [2, 4]],
    'any either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeStatus::ANY, [0, 1, 2, 3, 4]],
    'self only' => [ItemScopeSide::USER, ItemScopeStatus::ANY, [0]],
    'self must match status' => [ItemScopeSide::USER, ItemScopeStatus::DEAD, []],
    'no targets' => [ItemScopeSide::NONE, ItemScopeStatus::ANY, []],
  ]);

it('revalidates all-target membership and removes duplicate instance identities', function () {
  [$actor, $ally, $fallen, $opponent] = createPolicyTargets();
  $scope = new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ALL);
  $allies = [$actor, $actor, $ally, $fallen];
  $initial = BattleTargetPolicy::resolveTargets($scope, $actor, $allies, [$opponent], [$opponent]);
  $fallen->stats->currentHp = 25;
  $ally->stats->currentHp = 0;
  $current = BattleTargetPolicy::resolveTargets($scope, $actor, $allies, [$opponent], $initial);
  expect(array_map(spl_object_id(...), $initial))->toBe([spl_object_id($actor), spl_object_id($ally)])
    ->and(array_map(spl_object_id(...), $current))->toBe([spl_object_id($actor), spl_object_id($fallen)]);
});

it('keeps one valid preferred recipient and cannot cross into an illegal side', function () {
  [$actor, $ally, $fallen, $opponent] = createPolicyTargets();
  $scope = new ItemScope(ItemScopeSide::ALLY);
  $allies = [$actor, $ally, $fallen];
  expect(BattleTargetPolicy::resolveTargets($scope, $actor, $allies, [$opponent], [$ally])[0])->toBe($ally)
    ->and(BattleTargetPolicy::resolveTargets($scope, $actor, $allies, [$opponent], [$opponent, $fallen])[0])
    ->toBe($actor);
});

it('selects the authored random count once and retains the eligible recipients on revalidation', function () {
  [$actor, $ally, $fallen, $opponent] = createPolicyTargets();
  $scope = new ItemScope(ItemScopeSide::ENEMY_ALLY, ItemScopeNumber::RANDOM, targetCount: 2);
  $random = new TargetPolicyRandom();
  $selected = BattleTargetPolicy::resolveTargets($scope, $actor, [$actor, $ally, $fallen], [$opponent], random: $random);
  expect(array_map(spl_object_id(...), $selected))->toBe([spl_object_id($opponent), spl_object_id($ally)])
    ->and($random->calls)->toBe(2);
  $retained = BattleTargetPolicy::resolveTargets($scope, $actor, [$actor, $ally, $fallen], [$opponent], $selected, $random);
  expect(array_map(spl_object_id(...), $retained))->toBe(array_map(spl_object_id(...), $selected))
    ->and($random->calls)->toBe(2);
  $opponent->stats->currentHp = 0;
  $replacement = BattleTargetPolicy::resolveTargets($scope, $actor, [$actor, $ally, $fallen], [$opponent], $selected, $random);
  expect(array_map(spl_object_id(...), $replacement))->toBe([spl_object_id($ally), spl_object_id($actor)])
    ->and($random->calls)->toBe(3);
});

it('bounds random selection to unique eligible recipients', function (int $count, int $expected) {
  [$actor, $ally, $fallen, $opponent] = createPolicyTargets();
  $selected = BattleTargetPolicy::resolveTargets(
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::RANDOM, targetCount: $count),
    $actor, [$actor, $ally, $fallen], [$opponent], [$ally, $ally, $opponent], new TargetPolicyRandom());
  expect($selected)->toHaveCount($expected)
    ->and(count(array_unique(array_map(spl_object_id(...), $selected))))->toBe($expected);
})->with([[0, 1], [1, 1], [2, 2], [20, 2]]);

it('preserves explicit item scopes instead of overriding them from recovery effects', function () {
  $party = new Party();
  $actor = new Character('Caster', 1, new Stats());
  $party->addMember($actor);
  $item = new Item('Synthetic', '', '', 1, scope: new InventoryScope(
    ItemScopeSide::ENEMY_ALLY, ItemScopeNumber::RANDOM, ItemScopeStatus::ANY, 3),
    effects: [new HPRecoveryEffect('Recover', '', 10, 1, ValueBasis::ACTUAL)]);
  $party->inventory->addItems($item);
  $option = BattleCommandCatalog::buildOptions($actor, $party, 'Item')[0];
  $scope = $option->action->targetScope;
  expect($scope->side)->toBe(ItemScopeSide::ENEMY_ALLY)->and($scope->status)->toBe(ItemScopeStatus::ANY)
    ->and($scope->number)->toBe(ItemScopeNumber::RANDOM)->and($scope->targetCount)->toBe(3)
    ->and($option->targetSide)->toBe($scope->side)->and($option->targetStatus)->toBe($scope->status)
    ->and($option->targetNumber)->toBe($scope->number);
});

it('retains compatibility for old recovery items with the default no-scope value', function (bool $revival) {
  $item = new Item('Legacy', '', '', 1, effects: [$revival
    ? new ResurrectionEffect('Revive', '', 10, 1, ValueBasis::ACTUAL)
    : new HPRecoveryEffect('Recover', '', 10, 1, ValueBasis::ACTUAL)]);
  $scope = new ItemBattleAction($item)->targetScope;
  expect($scope->side)->toBe(ItemScopeSide::ALLY)
    ->and($scope->status)->toBe($revival ? ItemScopeStatus::DEAD : ItemScopeStatus::ALIVE)
    ->and($item->scope->side)->toBe(ItemScopeSide::NONE);
})->with([false, true]);

it('does not modify a skill definition when a consumer inspects its target scope', function () {
  $skill = new MagicSkill('Synthetic', '', '', 0, 0, new ItemScope(ItemScopeSide::ALLY));
  $action = new SkillBattleAction($skill);
  $inspected = $action->targetScope;
  $inspected->side = ItemScopeSide::ENEMY;
  expect($skill->scope->side)->toBe(ItemScopeSide::ALLY)->and($action->targetScope->side)->toBe(ItemScopeSide::ALLY);
});
