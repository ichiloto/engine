<?php

use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatResourceChange;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPDamageEffect;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Stats;

it('measures bounded resources and accumulates loss and restoration independently', function () {
  $battler = new Character('Recipient', 1, new Stats(currentHp: 20, totalHp: 100, currentMp: 5, totalMp: 20));
  $battler->stats->currentHp -= 50;
  $battler->stats->currentMp -= 50;
  $lost = CombatResourceChange::measure($battler, 20, 5);
  $battler->stats->currentHp += 200;
  $battler->stats->currentMp += 200;
  $restored = CombatResourceChange::measure($battler, 0, 0);
  $combined = $lost->accumulate($restored);
  expect([$combined->hpLost, $combined->hpRestored, $combined->mpLost, $combined->mpRestored])->toBe([20, 100, 5, 20])
    ->and($lost->hpRestored)->toBe(0)->and($combined->hasChanges)->toBeTrue()
    ->and((new CombatResourceChange())->hasChanges)->toBeFalse();
});

it('keeps repeated MP effects and same-name recipients distinct without including the one command cost', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 3, totalMp: 50));
  $targets = array_map(static fn() => new Character('Twin', 1,
    new Stats(currentHp: 100, totalHp: 100, currentMp: 50, totalMp: 50)), [0, 1]);
  $action = new SkillBattleAction(new MagicSkill('Drain', '', '', 3, 0,
    invocation: new SkillInvocation(repeat: 3), effects: [new MPDrainSkillEffect('2', variance: 0)]));
  $action->execute($actor, $targets);
  $results = $action->lastResult->targets;
  expect(array_map(static fn($result) => $result->targetId, $results))->toBe([
    CombatResolver::identity($targets[0]), CombatResolver::identity($targets[1]), CombatResolver::identity($actor),
  ])->and(array_map(static fn($result) => $result->resourceChange->mpLost, $results))->toBe([6, 6, 0])
    ->and(array_map(static fn($result) => $result->resourceChange->mpRestored, $results))->toBe([0, 0, 12])
    ->and($actor->stats->currentMp)->toBe(12)
    ->and(array_map(static fn($target) => $target->stats->currentMp, $targets))->toBe([44, 44]);
});

it('retains typed internal HP hits when an effect loses and restores HP within one application', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 50, totalHp: 100, currentMp: 20, totalMp: 20));
  $action = new SkillBattleAction(new MagicSkill('Self drain', '', '', 0, 0,
    effects: [new HPDrainSkillEffect('10', variance: 0)]));
  $action->execute($actor, [$actor]);
  expect($actor->stats->currentHp)->toBe(50)
    ->and($action->lastResult->actualHpLost())->toBeGreaterThan(0)
    ->and($action->lastResult->actualHpRestored())->toBe($action->lastResult->actualHpLost());
});

it('retains item hit results and never reuses a previous recipient hit when the effect skips a fallen target', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Recipient', 1, new Stats(currentHp: 40, totalHp: 100));
  $item = new Item('Mixed', '', '', 0, 3, effects: [
    new HPDamageEffect('Damage', '', 10, 1, ValueBasis::ACTUAL),
    new HPRecoveryEffect('Recover', '', 10, 1, ValueBasis::ACTUAL),
  ]);
  $action = new ItemBattleAction($item);
  $action->execute($actor, [$target]);
  expect($target->stats->currentHp)->toBe(40)->and($action->lastResult->actualHpLost())->toBe(10)
    ->and($action->lastResult->actualHpRestored())->toBe(10)->and($action->lastResult->hits())->toHaveCount(2);
  $target->stats->currentHp = 0;
  $action->execute($actor, [$target]);
  expect($action->lastResult->hits())->toBe([])->and($action->lastResult->actualHpLost())->toBe(0)
    ->and($action->lastResult->actualHpRestored())->toBe(0);
});
