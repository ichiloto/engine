<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Battle\Formation\BattleFormationChange;
use Ichiloto\Engine\Battle\Formation\BattlePosition;
use Ichiloto\Engine\Battle\Formation\BattleRow;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\FormationSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\GroupSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillEffectExecutor;
use Ichiloto\Engine\Entities\Skills\SkillGroupEffectContext;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Stats;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

it('applies a group phase once after all recipients have resolved', function () {
  $actor = new Character('Actor', 0, new Stats(currentMp: 50));
  $targets = [new Character('Twin', 0, new Stats(currentHp: 50, totalHp: 100)), new Character('Twin', 0, new Stats(currentHp: 50, totalHp: 100))];
  $observer = new class extends GroupSkillEffect {
    public int $calls = 0;
    public array $hp = [];
    public function applyToGroup(SkillGroupEffectContext $context): void {
      $this->calls++;
      $this->hp = array_map(static fn($target): int => $target->stats->currentHp, $context->getTargets());
    }
  };
  $skill = new BasicSkill('Group', '', '', 3, 0, new ItemScope(number: ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN,
    new SkillInvocation(accuracy: 100, repeat: 3), [$observer, new HPRecoverSkillEffect('2', variance: 0)]);
  new SkillEffectExecutor()->execute($skill, $actor, $targets, 'group', 'group:1');
  expect($observer->calls)->toBe(1)->and($observer->hp)->toBe([52, 52]);
});

it('shuffles occupied places once after repeated hits, charges once, and releases its execution context', function () {
  $actor = new Character('Actor', 0, new Stats(currentMp: 50, totalMp: 50));
  $targets = array_map(static fn(): Character => new Character('Twin', 0, new Stats(currentHp: 50, totalHp: 50, evasion: 0)), range(0, 2));
  $formation = new BattleFormation([$actor], $targets, [], [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::FRONT, 1)]);
  $random = new class implements CombatRandomSource {
    public array $shuffleCalls = [];
    public function nextInt(int $minimum, int $maximum): int {
      if ($minimum === 0) { $this->shuffleCalls[] = [$minimum, $maximum]; return 0; }
      return $maximum;
    }
  };
  $skill = new BasicSkill('Shuffle', '', '', 3, 0, new ItemScope(number: ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN,
    new SkillInvocation(accuracy: 100, repeat: 3), [new FormationSkillEffect(), new HPDamageSkillEffect('2', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE)]);
  $action = new SkillBattleAction($skill, random: $random);
  $action->executeWithReactions($actor, $targets, new BattleReactions(), formation: $formation);
  expect($random->shuffleCalls)->toBe([[0, 2], [0, 1]])
    ->and(array_map(static fn($target): int => $target->stats->currentHp, $targets))->toBe([44, 44, 44])
    ->and($actor->stats->currentMp)->toBe(47)
    ->and(array_map($formation->getPosition(...), $targets))->toEqual([new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::FRONT, 1), new BattlePosition(BattleRow::FRONT, 0)]);
  foreach ($action->lastResult->targets as $result) { expect($result->secondaryOutcomes)->toContain(['type' => 'formation', 'id' => 'shuffle', 'applied' => true]); }
  expect(fn() => $action->execute($actor, $targets))->toThrow(LogicException::class, 'current battle formation')
    ->and($actor->stats->currentMp)->toBe(47);
});

it('moves survivors to unique front places and leaves knocked-out recipients in place', function () {
  $actor = new Character('Actor', 0, new Stats());
  $front = new Character('Front', 0, new Stats(currentHp: 100));
  $back = new Character('Back', 0, new Stats(currentHp: 100));
  $fallen = new Character('Fallen', 0, new Stats(currentHp: 0));
  $targets = [$front, $back, $fallen];
  $formation = new BattleFormation([$actor], $targets, [], [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::BACK, 1)]);
  $effect = new FormationSkillEffect(BattleFormationChange::MOVE_TO_FRONT);
  $skill = new BasicSkill('Push', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN, effects: [$effect]);
  $result = new SkillEffectExecutor()->execute($skill, $actor, $targets, 'push', 'push:1', formation: $formation);
  expect($formation->getPosition($front))->toEqual(new BattlePosition(BattleRow::FRONT, 0))
    ->and($formation->getPosition($back))->toEqual(new BattlePosition(BattleRow::FRONT, 1))
    ->and($formation->getPosition($fallen))->toEqual(new BattlePosition(BattleRow::BACK, 1));
  expect(array_map(static fn($target): bool => $target->secondaryOutcomes[0]['applied'], $result->targets))->toBe([false, true, false]);
});

it('round trips explicit formation operations and rejects field usage or unknown operations', function () {
  $skill = new BasicSkill('Move', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN,
    effects: [new FormationSkillEffect(BattleFormationChange::MOVE_TO_FRONT)]);
  $data = SkillRecord::writeSkill($skill);
  expect($data['effects'])->toBe([['type' => 'change_formation', 'operation' => 'move_to_front']])
    ->and(SkillRecord::readSkill($data)->effects[0]->operation)->toBe(BattleFormationChange::MOVE_TO_FRONT);
  $data['effects'][0]['operation'] = 'teleport';
  expect(fn() => SkillRecord::readSkill($data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BasicSkill('Field', '', '', 0, 0, effects: [new FormationSkillEffect()]))->toThrow(InvalidArgumentException::class, 'battle-only');
});

it('passes the live formation into both traditional and ATB turn execution', function (bool $atb) {
  $prior = new ReflectionProperty(\Ichiloto\Engine\Core\Time::class, 'deltaTime')->getValue();
  try {
    $fixture = createTargetExecutionFixture($atb, false, $this->createStub(AudioManager::class));
    [$engine, $context, , $actor, , $targets] = $fixture;
    $engine->battleConfig->formation->shufflePositions($targets, new class implements CombatRandomSource {
      public function nextInt(int $minimum, int $maximum): int { return $minimum; }
    });
    $skill = new BasicSkill('Shuffle', '', '', 3, 0, new ItemScope(number: ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN,
      effects: [new FormationSkillEffect()]);
    $before = array_map($engine->battleConfig->formation->getPosition(...), $targets);
    $turn = queueTargetExecution($fixture, new SkillBattleAction($skill, random: new class implements CombatRandomSource {
      public function nextInt(int $minimum, int $maximum): int { return $minimum; }
    }), $targets);
    new ReflectionProperty(\Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($turn->isCompleted)->toBeTrue()->and($actor->stats->currentMp)->toBe(47)
      ->and(array_map($engine->battleConfig->formation->getPosition(...), $targets))->toEqual(array_reverse($before));
  } finally { new ReflectionProperty(\Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, $prior); }
})->with([false, true]);


it('leaves missed recipients in place and refuses foreign formation targets before charging or damaging', function () {
  $actor = new Character('Actor', 0, new Stats(currentMp: 50, totalMp: 50));
  $target = new Character('Target', 0, new Stats(currentHp: 50, totalHp: 50));
  $formation = new BattleFormation([$actor], [$target], [], [new BattlePosition(BattleRow::BACK, 0)]);
  $skill = new BasicSkill('Push', '', '', 3, 0, occasion: Occasion::BATTLE_SCREEN, invocation: new SkillInvocation(accuracy: 1),
    effects: [new HPDamageSkillEffect('5', variance: 0), new FormationSkillEffect(BattleFormationChange::MOVE_TO_FRONT)]);
  $action = new SkillBattleAction($skill, random: new class implements CombatRandomSource {
    public function nextInt(int $minimum, int $maximum): int { return $maximum; }
  });
  $action->executeWithReactions($actor, [$target], new BattleReactions(), formation: $formation);
  expect($target->stats->currentHp)->toBe(50)->and($formation->getPosition($target))->toEqual(new BattlePosition(BattleRow::BACK, 0))
    ->and($action->lastResult->targets[0]->secondaryOutcomes[0]['applied'])->toBeFalse();
  $foreign = new Character('Foreign', 0, new Stats(currentHp: 50));
  expect(fn() => $action->executeWithReactions($actor, [$foreign], new BattleReactions(), formation: $formation))
    ->toThrow(InvalidArgumentException::class, 'current battle participants')
    ->and($actor->stats->currentMp)->toBe(47)->and($foreign->stats->currentHp)->toBe(50)
    ->and(new ReflectionProperty($action, 'formation')->getValue($action))->toBeNull();
});


it('finishes damage for the entire group before moving only the surviving recipients', function () {
  $actor = new Character('Actor', 0, new Stats());
  $fallen = new Character('Fallen', 0, new Stats(currentHp: 5, totalHp: 5, evasion: 0));
  $survivor = new Character('Survivor', 0, new Stats(currentHp: 100, totalHp: 100, evasion: 0));
  $formation = new BattleFormation([$actor], [$fallen, $survivor], [], [new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::BACK, 1)]);
  $skill = new BasicSkill('Push', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN, invocation: new SkillInvocation(accuracy: 100),
    effects: [new FormationSkillEffect(BattleFormationChange::MOVE_TO_FRONT), new HPDamageSkillEffect('10', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE)]);
  $random = new class implements CombatRandomSource { public function nextInt(int $minimum, int $maximum): int { return $maximum; } };
  new SkillEffectExecutor(random: $random)->execute($skill, $actor, [$fallen, $survivor], 'push', 'push:1', formation: $formation);
  expect($fallen->isKnockedOut)->toBeTrue()->and($survivor->stats->currentHp)->toBe(90)
    ->and($formation->getPosition($fallen))->toEqual(new BattlePosition(BattleRow::BACK, 0))
    ->and($formation->getPosition($survivor))->toEqual(new BattlePosition(BattleRow::FRONT, 1));
});
