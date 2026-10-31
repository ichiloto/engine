<?php

use Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\CrisisAbility;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;

function crisisTestData(array $overrides = []): array
{
  return array_replace(['name' => 'Surge', 'hpBelowPercent' => 10, 'states' => ['focus'],
    'variants' => [['skill' => 'Test finisher']]], $overrides);
}

function crisisTestActor(?CrisisAbility $ability = null, string $id = 'synthetic-actor'): Character
{
  $actor = new Character('Test actor', 1, new Stats(), actorId: $id, crisisAbility: $ability ?? CrisisAbility::fromArray(crisisTestData()));
  $actor->stats->totalHp = 100;
  $actor->stats->currentHp = 9;
  $actor->stats->totalMp = 30;
  $actor->stats->currentMp = 7;
  return $actor;
}

function withCrisisTestProject(callable $assertion): void
{
  $root = createTestDirectory('ichiloto-crisis-');
  $previous = getcwd();
  writeSkillRecords($root, new SpecialSkill('Test finisher', 'A synthetic finishing technique.', '', 0, 0),
    new SpecialSkill('Second form', 'Another synthetic technique.', '', 0, 0));
  try { chdir($root); $assertion(); }
  finally { chdir($previous); }
}

it('derives crisis activation at the strict HP boundary without changing MP', function () {
  $actor = crisisTestActor();
  foreach ([100 => false, 10 => false, 9 => true, 1 => true, 0 => false] as $hp => $active) {
    $actor->stats->currentHp = $hp;
    expect($actor->crisisAbility->isActive($actor))->toBe($active)
      ->and($actor->stats->currentMp)->toBe(7);
  }
});

it('activates through an authored state and removes availability when that state ends', function () {
  $actor = crisisTestActor();
  $actor->stats->currentHp = 100;
  expect($actor->crisisAbility->isActive($actor))->toBeFalse();
  $actor->addState(new State('focus', 'Focus'));
  expect($actor->crisisAbility->isActive($actor))->toBeTrue();
  $actor->removeState('focus');
  expect($actor->crisisAbility->isActive($actor))->toBeFalse()
    ->and($actor->stats->currentMp)->toBe(7);
});

it('keeps the ordinary Attack and adds only the active actors crisis technique', function () {
  withCrisisTestProject(function () {
    $actor = crisisTestActor();
    $party = new Party();
    $party->addMember($actor);
    $options = BattleCommandCatalog::buildOptions($actor, $party, BattleCommandType::ATTACK);
    expect(array_column($options, 'label'))->toBe(['Attack', 'Test finisher'])
      ->and($options[1]->action)->toBeInstanceOf(CrisisSkillBattleAction::class)
      ->and(BattleCommandCatalog::getActionType($options[1]->action))->toBe(BattleCommandType::ATTACK);
    $actor->stats->currentHp = 10;
    expect(array_column(BattleCommandCatalog::buildOptions($actor, $party, 'Attack'), 'label'))->toBe(['Attack']);
    $other = new Character('Other', 1, new Stats());
    expect(array_column(BattleCommandCatalog::buildOptions($other, $party, 'Attack'), 'label'))->toBe(['Attack']);
  });
});

it('rechecks healing and form changes before a queued technique spends resources', function () {
  withCrisisTestProject(function () {
    $ability = CrisisAbility::fromArray(crisisTestData(['variants' => [
      ['skill' => 'Test finisher', 'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => 'first']]],
      ['skill' => 'Second form', 'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => 'second']]],
    ]]));
    $actor = crisisTestActor($ability);
    $party = new Party(); $party->addMember($actor);
    $state = new GameState(); $state->setVariable('form', 'first');
    $options = BattleCommandCatalog::buildOptions($actor, $party, 'Attack', gameState: $state);
    $action = $options[1]->action;
    expect($action->getExecutionRefusal($actor))->toBeNull();
    $actor->stats->currentHp = 100;
    expect($action->getExecutionRefusal($actor))->not->toBeNull();
    $action->execute($actor, []);
    expect($actor->stats->currentMp)->toBe(7);
    $actor->stats->currentHp = 9;
    $state->setVariable('form', 'second');
    expect($action->getExecutionRefusal($actor))->not->toBeNull()
      ->and(array_column(BattleCommandCatalog::buildOptions($actor, $party, 'Attack', gameState: $state), 'label'))
      ->toBe(['Attack', 'Second form']);
    expect($action->getExecutionRefusal(crisisTestActor($ability, 'other-actor')))->not->toBeNull();
  });
});

it('keeps guarded form variants unavailable without world context', function () {
  $ability = CrisisAbility::fromArray(crisisTestData(['variants' => [
    ['skill' => 'Test finisher', 'conditions' => [['type' => 'switch', 'name' => 'form_unlocked']]],
  ]]));
  expect($ability->getAvailableSkill(crisisTestActor($ability)))->toBeNull();
});

it('resolves the unlocked form for acquisition presentation independently of battle activation', function () {
  withCrisisTestProject(function () {
    $ability = CrisisAbility::fromArray(crisisTestData(['variants' => [
      ['skill' => 'Test finisher', 'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => 'first']]],
      ['skill' => 'Second form', 'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => 'second']]],
    ]]));
    $actor = crisisTestActor($ability); $actor->stats->currentHp = 100;
    $state = new GameState(); $before = $actor->toArray();
    expect($ability->getUnlockedSkill($actor))->toBeNull()
      ->and($ability->getUnlockedSkill($actor, $state))->toBeNull();
    $state->setVariable('form', 'first');
    expect($ability->getUnlockedSkill($actor, $state)->name)->toBe('Test finisher')
      ->and($ability->getAvailableSkill($actor, $state))->toBeNull()
      ->and($actor->toArray())->toBe($before);
    $state->setVariable('form', 'second');
    expect($ability->getUnlockedSkill($actor, $state)->name)->toBe('Second form')
      ->and($ability->getAvailableSkill($actor, $state))->toBeNull()
      ->and($actor->stats->currentMp)->toBe(7);
    $actor->stats->currentHp = 9;
    expect($ability->getAvailableSkill($actor, $state)->name)->toBe('Second form')
      ->and($actor->stats->currentMp)->toBe(7);
  });
});

it('executes an eligible crisis technique through the normal skill effect and MP contract', function () {
  withCrisisTestProject(function () {
    $skill = new SpecialSkill('Paid finisher', 'Synthetic combat effect.', '', 3, 0,
      effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect('12', variance: 0)]);
    writeSkillRecords(getcwd(), $skill);
    $ability = CrisisAbility::fromArray(crisisTestData(['variants' => [['skill' => 'Paid finisher']]]));
    $actor = crisisTestActor($ability);
    $party = new Party(); $party->addMember($actor);
    $target = crisisTestActor(null, 'target'); $target->stats->currentHp = 100;
    $option = BattleCommandCatalog::buildOptions($actor, $party, 'Attack')[1];
    expect($option->mpCost)->toBe(3)->and($option->label)->toContain('3 MP');
    $option->action->execute($actor, [$target]);
    expect($actor->stats->currentMp)->toBe(4)->and($target->stats->currentHp)->toBeLessThan(100);
  });
});

it('rejects overlapping form variants instead of silently choosing a form', function () {
  withCrisisTestProject(function () {
    $ability = CrisisAbility::fromArray(crisisTestData(['variants' => [
      ['skill' => 'Test finisher'], ['skill' => 'Second form'],
    ]]));
    expect(fn() => $ability->getAvailableSkill(crisisTestActor($ability)))->toThrow(InvalidArgumentException::class, 'overlapping');
  });
});

it('renders a current crisis badge without persisting derived availability', function () {
  $actor = crisisTestActor();
  expect(array_column(BattlerConditions::getEntries($actor), 'name'))->toContain('Surge')
    ->and($actor->toArray())->not->toHaveKey('crisisAbility');
  $actor->stats->currentHp = 100;
  expect(array_column(BattlerConditions::getEntries($actor), 'name'))->not->toContain('Surge');
});

it('uses current actor source rules when restoring old mutable state', function () {
  withCrisisTestProject(function () {
    file_put_contents(getcwd() . '/assets/Data/crisis-abilities.php', '<?php return ' . var_export([
      ['id' => 'test-surge', ...crisisTestData(['name' => 'Current surge'])],
    ], true) . ';');
    $actor = crisisTestActor();
    $data = $actor->toArray();
    $definition = ActorDefinition::fromArray(['data' => ['name' => 'Test actor', 'currentExp' => 1,
      'stats' => $actor->stats->jsonSerialize(), 'images' => [], 'id' => 'synthetic-actor',
      'crisisAbility' => 'test-surge']]);
    $restored = $definition->createCharacter([...$data, 'crisisAbility' => ['name' => 'Stale save']]);
    expect($restored->crisisAbility?->name)->toBe('Current surge')
      ->and($restored->stats->currentMp)->toBe(7);
  });
});

it('rejects malformed crisis configuration', function (array $overrides) {
  expect(fn() => CrisisAbility::fromArray(crisisTestData($overrides)))->toThrow(InvalidArgumentException::class);
})->with([
  'zero threshold' => [['hpBelowPercent' => 0]],
  'impossible threshold' => [['hpBelowPercent' => 101]],
  'nonfinite threshold' => [['hpBelowPercent' => INF]],
  'no activation' => [['hpBelowPercent' => null, 'states' => []]],
  'duplicate states' => [['states' => ['focus', 'focus']]],
  'no technique' => [['variants' => []]],
  'empty skill' => [['variants' => [['skill' => '']]]],
  'unknown condition' => [['variants' => [['skill' => 'Test finisher', 'conditions' => [['type' => 'unknown', 'name' => 'x']]]]]],
]);

it('reports missing skill references against the source configuration', function () {
  $ability = CrisisAbility::fromArray(crisisTestData());
  expect(fn() => $ability->validateSkills(SkillCatalog::fromSkills([])))
    ->toThrow(InvalidArgumentException::class, 'Test finisher');
});
