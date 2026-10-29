<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\ActualHpLossAggregatePolicy;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ElementalAffinityResolver;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Skills\SkillResolutionScope;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

function foundationBattler(string $name, Stats $stats): Enemy
{
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();

  foreach ([
    'name' => $name,
    'level' => 1,
    'stats' => $stats,
    'imagePath' => '',
    'image' => ['@'],
    'position' => new Vector2(),
  ] as $property => $value) {
    new ReflectionProperty(Enemy::class, $property)->setValue($enemy, $value);
  }

  return $enemy;
}

function sequenceRandom(int ...$values): CombatRandomSource
{
  return new class($values) implements CombatRandomSource {
    public function __construct(private array $values)
    {
    }

    public function nextInt(int $minimum, int $maximum): int
    {
      return intval(array_shift($this->values) ?? $minimum);
    }
  };
}

function resolveFoundationHit(
  Enemy $actor,
  Enemy $target,
  ResolutionKind $kind,
  int $raw,
  ?string $element = null,
  ?int $accuracy = null,
  bool $critical = false,
  bool $guaranteedCritical = false,
  ?CombatRandomSource $random = null,
): Ichiloto\Engine\Battle\Resolution\CombatHitResult {
  return new CombatResolver()->resolve(new CombatResolutionRequest(
    actionId: 'test.action',
    executionId: 'test.action:1',
    actor: $actor,
    target: $target,
    rawMagnitude: $raw,
    kind: $kind,
    element: $element,
    baseAccuracy: $accuracy,
    criticalEligible: $critical,
    guaranteedCritical: $guaranteedCritical,
  ), $random);
}

it('applies physical and magical defence once through one diminishing family', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, attack: 100, magicAttack: 100));
  $physical = foundationBattler('Physical', new Stats(currentHp: 200, totalHp: 200, defence: 240));
  $magical = foundationBattler('Magical', new Stats(currentHp: 200, totalHp: 200, magicDefence: 240));
  $capped = foundationBattler('Capped', new Stats(currentHp: 200, totalHp: 200, defence: 9_999));

  $physicalResult = resolveFoundationHit($actor, $physical, ResolutionKind::PHYSICAL_DAMAGE, 100);
  $magicalResult = resolveFoundationHit($actor, $magical, ResolutionKind::MAGICAL_DAMAGE, 100);
  $cappedResult = resolveFoundationHit($actor, $capped, ResolutionKind::PHYSICAL_DAMAGE, 100);

  expect($physicalResult->mitigationRate)->toBe(0.5)
    ->and($physicalResult->mitigationAmount)->toBe(50)
    ->and($physicalResult->actualHpLost)->toBe(50)
    ->and($magicalResult->mitigationRate)->toBe(0.5)
    ->and($magicalResult->actualHpLost)->toBe(50)
    ->and($cappedResult->mitigationRate)->toBe(0.75)
    ->and($cappedResult->actualHpLost)->toBe(25);
});

it('reports misses guaranteed hits criticals and guard as explicit steps', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, attack: 100, grace: 0));
  $missTarget = foundationBattler('Miss', new Stats(currentHp: 200, totalHp: 200, evasion: 100));
  $miss = resolveFoundationHit(
    $actor,
    $missTarget,
    ResolutionKind::PHYSICAL_DAMAGE,
    100,
    accuracy: 5,
    critical: true,
    random: sequenceRandom(100),
  );

  $guardTarget = foundationBattler('Guard', new Stats(currentHp: 200, totalHp: 200, defence: 0));
  $guardTarget->beginGuarding();
  $criticalGuard = resolveFoundationHit(
    $actor,
    $guardTarget,
    ResolutionKind::PHYSICAL_DAMAGE,
    100,
    accuracy: null,
    critical: true,
    random: sequenceRandom(1),
  );

  expect($miss->hit)->toBeFalse()
    ->and($miss->missReason)->toBe('accuracyRoll')
    ->and($missTarget->stats->currentHp)->toBe(200)
    ->and($criticalGuard->hitRoll)->toBeNull()
    ->and($criticalGuard->critical)->toBeTrue()
    ->and($criticalGuard->guardApplied)->toBeTrue()
    ->and($criticalGuard->actualHpLost)->toBe(75);
});

it('distinguishes an authored guaranteed Critical from a bounded Critical chance', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, grace: 0));
  $target = foundationBattler('Target', new Stats(currentHp: 200, totalHp: 200));

  $result = resolveFoundationHit(
    $actor,
    $target,
    ResolutionKind::TRUE_DAMAGE,
    20,
    critical: true,
    guaranteedCritical: true,
    random: sequenceRandom(100),
  );

  expect($result->critical)->toBeTrue()
    ->and($result->criticalRoll)->toBeNull()
    ->and($result->actualHpLost)->toBe(30);
});

it('separates requested and actual healing damage and overkill', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100));
  $healed = foundationBattler('Healed', new Stats(currentHp: 90, totalHp: 100));
  $healing = resolveFoundationHit($actor, $healed, ResolutionKind::HEALING, 50);
  $victim = foundationBattler('Victim', new Stats(currentHp: 30, totalHp: 100));
  $damage = resolveFoundationHit($actor, $victim, ResolutionKind::TRUE_DAMAGE, 80);

  expect($healing->requestedHpChange)->toBe(50)
    ->and($healing->actualHpRestored)->toBe(10)
    ->and($healing->criticalEligible)->toBeFalse()
    ->and($healing->criticalRoll)->toBeNull()
    ->and($healing->critical)->toBeFalse()
    ->and($healing->postEffectHp)->toBe(100)
    ->and($damage->requestedHpChange)->toBe(-80)
    ->and($damage->actualHpLost)->toBe(30)
    ->and($damage->overkill)->toBe(50)
    ->and($damage->postEffectHp)->toBe(0)
    ->and(new CombatTargetResult('Victim', [$damage])->getResolvedHpDamage())->toBe(80)
    ->and(new CombatTargetResult('Healed', [$healing])->getResolvedHpDamage())->toBe(0);
});

it('represents every elemental outcome and bounds ordinary composition', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100));
  $outcomes = [];

  foreach ([
    'normal' => 1.0,
    'weak' => 2.0,
    'resist' => 0.5,
    'null' => 0.0,
    'absorb' => -1.0,
  ] as $name => $multiplier) {
    $target = foundationBattler($name, new Stats(currentHp: 50, totalHp: 100));
    $target->setElementAffinities(['Fire' => $multiplier]);
    $outcomes[$name] = resolveFoundationHit($actor, $target, ResolutionKind::TRUE_DAMAGE, 20, 'Fire');
  }

  expect($outcomes['normal']->elementalOutcome)->toBe(ElementalOutcome::NORMAL)
    ->and($outcomes['weak']->elementalOutcome)->toBe(ElementalOutcome::WEAK)
    ->and($outcomes['resist']->elementalOutcome)->toBe(ElementalOutcome::RESIST)
    ->and($outcomes['null']->elementalOutcome)->toBe(ElementalOutcome::NULL)
    ->and($outcomes['null']->actualHpLost)->toBe(0)
    ->and($outcomes['absorb']->elementalOutcome)->toBe(ElementalOutcome::ABSORB)
    ->and($outcomes['absorb']->actualHpRestored)->toBe(20)
    ->and(ElementalAffinityResolver::compose([0.5, 0.5, 0.5])['multiplier'])->toBe(0.25)
    ->and(ElementalAffinityResolver::compose([2.0, 2.0])['multiplier'])->toBe(3.0)
    ->and(ElementalAffinityResolver::compose([0.0, -1.0])['outcome'])->toBe(ElementalOutcome::NULL);
});

it('aggregates ordered repeated and multi-target hits without double counting', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100));
  $first = foundationBattler('First', new Stats(currentHp: 100, totalHp: 100));
  $second = foundationBattler('Second', new Stats(currentHp: 100, totalHp: 100));
  $one = resolveFoundationHit($actor, $first, ResolutionKind::TRUE_DAMAGE, 10);
  $two = resolveFoundationHit($actor, $first, ResolutionKind::TRUE_DAMAGE, 15);
  $three = resolveFoundationHit($actor, $second, ResolutionKind::TRUE_DAMAGE, 20);
  $action = new CombatActionResult('test', 'test:1', 'Actor', [
    new CombatTargetResult('First', [$one, $two]),
    new CombatTargetResult('Second', [$three]),
  ]);

  expect($action->hits())->toBe([$one, $two, $three])
    ->and($action->actualHpLost())->toBe(45)
    ->and($action->actualHpRestored())->toBe(0)
    ->and($action->hitCount())->toBe(3)
    ->and($action->targetCount())->toBe(2)
    ->and($action->defeatedTargetCount())->toBe(0);
});

it('derives bounded future effects from actual action loss rather than overkill', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100));
  $target = foundationBattler('Target', new Stats(currentHp: 30, totalHp: 100));
  $overkill = resolveFoundationHit($actor, $target, ResolutionKind::TRUE_DAMAGE, 100);
  $action = new CombatActionResult('test', 'test:1', 'Actor', [
    new CombatTargetResult('Target', [$overkill]),
  ]);
  $policy = new ActualHpLossAggregatePolicy(0.25);

  expect($action->targets[0]->getResolvedHpDamage())->toBe(100)
    ->and($action->actualHpLost())->toBe(30)
    ->and($action->defeatedTargetCount())->toBe(1)
    ->and($policy->magnitudeFor($action))->toBe(8)
    ->and(fn() => new ActualHpLossAggregatePolicy(0.26))
    ->toThrow(InvalidArgumentException::class);
});

it('repeats declared hit effects without repeating cost or unrelated side effects', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20));
  $target = foundationBattler('Target', new Stats(currentHp: 500, totalHp: 500));
  $secondary = new class('0') extends SkillEffect {
    public int $applications = 0;

    public function apply(SkillEffectContext $context): void
    {
      $this->applications++;
      $context->recordSecondaryOutcome('test', 'once', true);
    }
  };
  $skill = new SpecialSkill(
    'Triple Test',
    'Test repeat semantics.',
    '/',
    4,
    0,
    new ItemScope(),
    Occasion::BATTLE_SCREEN,
    new SkillInvocation(repeat: 3, accuracy: 100),
    [new HPDamageSkillEffect('10', variance: 0.0), $secondary],
  );
  $action = new SkillBattleAction($skill, random: new SeededCombatRandomSource(3));

  $action->execute($actor, [$target]);

  expect($actor->stats->currentMp)->toBe(16)
    ->and($action->lastResult?->hitCount())->toBe(3)
    ->and($secondary->applications)->toBe(1)
    ->and($action->lastResult?->targets[0]->secondaryOutcomes)->toBe([
      ['type' => 'test', 'id' => 'once', 'applied' => true],
    ]);
});

it('drains from actual HP lost and keeps actor and victim target aggregates distinct', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 20, totalHp: 100, currentMp: 10, totalMp: 10));
  $target = foundationBattler('Target', new Stats(currentHp: 30, totalHp: 100));
  $skill = new SpecialSkill(
    'Drain Test',
    'Test actual-loss drain.',
    '/',
    0,
    0,
    new ItemScope(),
    Occasion::BATTLE_SCREEN,
    effects: [new HPDrainSkillEffect('100', variance: 0.0)],
  );
  $action = new SkillBattleAction($skill, random: new SeededCombatRandomSource(5));

  $action->execute($actor, [$target]);

  expect($target->stats->currentHp)->toBe(0)
    ->and($actor->stats->currentHp)->toBe(50)
    ->and($action->lastResult?->actualHpLost())->toBe(30)
    ->and($action->lastResult?->actualHpRestored())->toBe(30)
    ->and($action->lastResult?->targets[0]->getResolvedHpDamage())->toBe(98)
    ->and($action->lastResult?->targets[1]->getResolvedHpDamage())->toBe(0)
    ->and(array_map(static fn(CombatTargetResult $result): string => $result->targetId, $action->lastResult?->targets ?? []))
    ->toBe([CombatResolver::identity($target), CombatResolver::identity($actor)]);
});

it('honours declared multi-effect roll scope without merging equal-name targets', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20));
  $first = foundationBattler('Twin', new Stats(currentHp: 100, totalHp: 100));
  $second = foundationBattler('Twin', new Stats(currentHp: 100, totalHp: 100));
  $skill = new SpecialSkill(
    'Shared Roll',
    'Test declared multi-effect roll scope.',
    '/',
    3,
    0,
    new ItemScope(),
    Occasion::BATTLE_SCREEN,
    new SkillInvocation(
      accuracy: 70,
      repeat: 2,
      hitScope: SkillResolutionScope::PER_ACTION,
      criticalScope: SkillResolutionScope::PER_ACTION,
    ),
    [new HPDamageSkillEffect('10', variance: 0.0)],
  );
  $action = new SkillBattleAction($skill, random: sequenceRandom(10, 25, 80, 10, 10, 10));

  $action->execute($actor, [$first, $second]);

  $hits = $action->lastResult?->hits() ?? [];
  expect($actor->stats->currentMp)->toBe(17)
    ->and($action->lastResult?->targetCount())->toBe(2)
    ->and($action->lastResult?->hitCount())->toBe(4)
    ->and(array_unique(array_map(static fn($hit): ?int => $hit->hitRoll, $hits)))->toBe([25])
    ->and(array_unique(array_map(static fn($hit): ?int => $hit->criticalRoll, $hits)))->toBe([80])
    ->and($action->lastResult?->targets[0]->targetId)->not->toBe($action->lastResult?->targets[1]->targetId);
});

it('matches seeded live and simulator resolution at the typed action boundary', function () {
  $liveActor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, attack: 40, grace: 8));
  $liveTarget = foundationBattler('Target', new Stats(currentHp: 100, totalHp: 100, defence: 12, evasion: 3));
  $live = new AttackAction('Attack', new CombatResolver(), new SeededCombatRandomSource(42));
  $live->execute($liveActor, [$liveTarget]);

  $simActor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, attack: 40, grace: 8));
  $simTarget = foundationBattler('Target', new Stats(currentHp: 100, totalHp: 100, defence: 12, evasion: 3));
  $simulated = new BattleSimulator(seed: 42)->previewAttack($simActor, $simTarget);

  expect($live->lastResult?->hits()[0]->actualHpLost)->toBe($simulated->hits()[0]->actualHpLost)
    ->and($live->lastResult?->hits()[0]->hitRoll)->toBe($simulated->hits()[0]->hitRoll)
    ->and($live->lastResult?->hits()[0]->criticalRoll)->toBe($simulated->hits()[0]->criticalRoll);
});

it('lets a physical skill carry an element through the target affinity', function () {
  // A synthetic special skill read from record data as the Editor writes it: an element, no resolution override.
  $skill = SkillRecord::readSkill([
    'kind' => 'special',
    'name' => 'Synthetic Edge',
    'description' => 'A synthetic element-bearing physical strike.',
    'icon' => '/',
    'cost' => 0,
    'cooldown' => 0,
    'occasion' => 'Battle Screen',
    'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '%2', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'effects' => [['type' => 'hp_damage', 'formula' => '$user->stats->attack * 2', 'variance' => 0.0, 'element' => 'Light']],
  ]);
  // Every roll lands on its highest value: no variance spread and no critical.
  $random = new class implements CombatRandomSource {
    public function nextInt(int $minimum, int $maximum): int
    {
      return $maximum;
    }
  };
  $hits = [];

  foreach (['neutral' => null, 'weak' => 2.0, 'resist' => 0.5] as $name => $multiplier) {
    $actor = foundationBattler('Actor', new Stats(currentHp: 100, totalHp: 100, attack: 40, magicAttack: 0));
    // Defence 60 mitigates a physical hit by 20%; with no Magic Defence a magical resolution would mitigate nothing.
    $target = foundationBattler($name, new Stats(currentHp: 500, totalHp: 500, defence: 60, magicDefence: 0));
    if ($multiplier !== null) {
      $target->setElementAffinities(['Light' => $multiplier]);
    }
    $action = new SkillBattleAction($skill, random: $random);
    $action->execute($actor, [$target]);
    $hits[$name] = $action->lastResult?->hits()[0];
  }

  expect($skill)->toBeInstanceOf(SpecialSkill::class)
    ->and(array_map(static fn($hit): ResolutionKind => $hit->kind, $hits))
    ->toBe(array_fill_keys(['neutral', 'weak', 'resist'], ResolutionKind::PHYSICAL_DAMAGE))
    ->and(array_map(static fn($hit): int => $hit->offensiveInput, $hits))->toBe(['neutral' => 40, 'weak' => 40, 'resist' => 40])
    ->and(array_map(static fn($hit): int => $hit->defensiveInput, $hits))->toBe(['neutral' => 60, 'weak' => 60, 'resist' => 60])
    ->and(array_map(static fn($hit): ?string => $hit->element, $hits))->toBe(['neutral' => 'Light', 'weak' => 'Light', 'resist' => 'Light'])
    ->and($hits['neutral']->elementalOutcome)->toBe(ElementalOutcome::NORMAL)
    ->and($hits['weak']->elementalOutcome)->toBe(ElementalOutcome::WEAK)
    ->and($hits['resist']->elementalOutcome)->toBe(ElementalOutcome::RESIST)
    ->and(array_map(static fn($hit): int => $hit->actualHpLost, $hits))->toBe(['neutral' => 64, 'weak' => 128, 'resist' => 32]);
});


it('supports authored protection piercing with true damage while preserving ordinary protection and encounter floors', function () {
  $actor = foundationBattler('Actor', new Stats(currentHp: 100));
  $target = foundationBattler('Target', new Stats(currentHp: 100, totalHp: 100, defence: 999, magicDefence: 999));
  $target->beginGuarding();
  $target->addState(\Ichiloto\Engine\Entities\States\State::fromArray(['id' => 'barrier', 'name' => 'Barrier', 'damageTakenRate' => 0]));
  $effect = new HPDamageSkillEffect('$target->stats->currentHp', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE);
  $context = new SkillEffectContext($actor, $target, random: sequenceRandom(100, 100, 100));
  $effect->apply($context);
  expect($target->stats->currentHp)->toBe(100);
  $piercing = new HPDamageSkillEffect('$target->stats->currentHp', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE, ignoresProtection: true);
  $piercing->apply(new SkillEffectContext($actor, $target, random: sequenceRandom(100, 100, 100)));
  expect($target->stats->currentHp)->toBe(0);
  $target->stats->currentHp = 100;
  $target->setKnockoutFloorPercent(1);
  $piercing->apply(new SkillEffectContext($actor, $target, random: sequenceRandom(100, 100, 100)));
  expect($target->stats->currentHp)->toBe(1);
});

it('retains ordinary protection in historical damage effects and strictly reads the optional piercing flag', function () {
  $effect = new HPDamageSkillEffect('5');
  Closure::bind(function (): void { unset($this->ignoresProtection); }, $effect, HPDamageSkillEffect::class)();
  expect(unserialize(serialize($effect))->ignoresProtection)->toBeFalse();
  $skill = new SpecialSkill('Pierce', '', '', 0, 0, effects: [new HPDamageSkillEffect('5', ignoresProtection: true)]);
  $data = SkillRecord::writeSkill($skill);
  expect($data['effects'][0]['ignoresProtection'])->toBeTrue()
    ->and(SkillRecord::readSkill($data)->effects[0]->ignoresProtection)->toBeTrue();
  $data['effects'][0]['ignoresProtection'] = 'true';
  expect(fn() => SkillRecord::readSkill($data))->toThrow(InvalidArgumentException::class);
});
