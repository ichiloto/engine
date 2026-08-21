<?php

use Ichiloto\Engine\Entities\States\HasStates;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateInstance;
use Ichiloto\Engine\Entities\Stats;

function makeAfflictable(int $hp = 100): object
{
  return new class($hp) {
    use HasStates;

    public Stats $stats;
    public bool $isKnockedOut {
      get {
        return $this->stats->currentHp <= 0;
      }
    }

    public function __construct(int $hp)
    {
      $this->stats = new Stats(currentHp: $hp, totalHp: $hp, currentMp: 10);
    }
  };
}

function poisonState(): State
{
  return State::fromArray([
    'id' => 'poison',
    'name' => 'Poison',
    'tickFormula' => '-max(1, intval($target->stats->totalHp * 0.1))',
    'persistsAfterBattle' => true,
  ]);
}

function stunState(): State
{
  return State::fromArray([
    'id' => 'stun',
    'name' => 'Stun',
    'durationTurns' => 2,
    'preventsAction' => true,
  ]);
}

it('hydrates states from data entries', function () {
  $state = poisonState();

  expect($state->id)->toBe('poison')
    ->and($state->durationTurns)->toBeNull()
    ->and($state->persistsAfterBattle)->toBeTrue()
    ->and(fn() => State::fromArray(['name' => 'No Id']))->toThrow(InvalidArgumentException::class);
});

it('inflicts and cures states', function () {
  $battler = makeAfflictable();

  expect($battler->addState(poisonState()))->toBeTrue()
    ->and($battler->hasState('poison'))->toBeTrue()
    ->and($battler->addState(poisonState()))->toBeFalse() // no double stacks
    ->and($battler->removeState('poison'))->toBeTrue()
    ->and($battler->hasState('poison'))->toBeFalse()
    ->and($battler->removeState('poison'))->toBeFalse();
});

it('respects immunity through state resistances', function () {
  $battler = makeAfflictable();
  $battler->setStateResistances(['poison' => 0.0]);

  expect($battler->addState(poisonState(), 100))->toBeFalse()
    ->and($battler->hasState('poison'))->toBeFalse();
});

it('never inflicts at zero chance', function () {
  $battler = makeAfflictable();

  expect($battler->addState(poisonState(), 0))->toBeFalse();
});

it('ticks HP formulas with a damage floor of one', function () {
  $battler = makeAfflictable(100);
  $battler->addState(poisonState());

  $events = $battler->tickStates();

  expect($events)->toHaveCount(1)
    ->and($events[0]['hpDelta'])->toBe(-10)
    ->and($battler->stats->currentHp)->toBe(90)
    ->and($events[0]['expired'])->toBeFalse()
    ->and($battler->hasState('poison'))->toBeTrue();
});

it('expires timed states after their duration', function () {
  $battler = makeAfflictable();
  $battler->addState(stunState());

  expect($battler->getActionBlockingState()?->id)->toBe('stun');

  $first = $battler->tickStates();
  expect($first[0]['expired'])->toBeFalse()
    ->and($battler->hasState('stun'))->toBeTrue();

  $second = $battler->tickStates();
  expect($second[0]['expired'])->toBeTrue()
    ->and($battler->hasState('stun'))->toBeFalse()
    ->and($battler->getActionBlockingState())->toBeNull();
});

it('keeps only persistent states after battle', function () {
  $battler = makeAfflictable();
  $battler->addState(poisonState());
  $battler->addState(stunState());

  $battler->clearBattleStates();

  expect($battler->hasState('poison'))->toBeTrue()
    ->and($battler->hasState('stun'))->toBeFalse();
});

it('resolves elemental affinities case-insensitively with a neutral default', function () {
  $battler = makeAfflictable();
  $battler->setElementAffinities(['Fire' => 2.0, 'water' => -1.0, 'Ice' => 0.0, 'Wind' => 0.5]);

  expect($battler->getElementMultiplier('fire'))->toBe(2.0)
    ->and($battler->getElementMultiplier('WATER'))->toBe(-1.0)
    ->and($battler->getElementMultiplier('Ice'))->toBe(0.0)
    ->and($battler->getElementMultiplier('Wind'))->toBe(0.5)
    ->and($battler->getElementMultiplier('Thunder'))->toBe(1.0)
    ->and($battler->getElementMultiplier(null))->toBe(1.0)
    ->and($battler->getElementMultiplier(''))->toBe(1.0);
});

it('raises and drops guard, and never carries it out of battle', function () {
  $battler = makeAfflictable();

  expect($battler->isGuarding)->toBeFalse();

  $battler->beginGuarding();
  expect($battler->isGuarding)->toBeTrue();

  $battler->stopGuarding();
  expect($battler->isGuarding)->toBeFalse();

  $battler->beginGuarding();
  $battler->clearBattleStates();
  expect($battler->isGuarding)->toBeFalse();
});

it('learns level-up skills directly and never twice', function () {
  $book = new Ichiloto\Engine\Entities\Abilities\AbilityBook();
  $skill = new Ichiloto\Engine\Entities\Skills\BasicSkill(
    'Whirlwind',
    '',
    '',
    8,
    0,
    new Ichiloto\Engine\Entities\ItemScope(),
    Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN,
    new Ichiloto\Engine\Entities\Skills\SkillInvocation()
  );

  expect($book->learnSkillDirectly($skill))->toBeTrue()
    ->and($book->learnSkillDirectly($skill))->toBeFalse()
    ->and(array_map(fn($ability) => $ability->name, $book->getLearnedAbilities()))->toContain('Whirlwind');
});

it('tracks remaining turns on instances', function () {
  $instance = new StateInstance(stunState(), 1);

  expect($instance->tickDuration())->toBeTrue()
    ->and($instance->remainingTurns)->toBe(0);

  $indefinite = new StateInstance(poisonState(), null);
  expect($indefinite->tickDuration())->toBeFalse();
});
