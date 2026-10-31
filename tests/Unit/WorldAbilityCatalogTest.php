<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Field\DashSettings;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;

beforeEach(function () {
  $this->priorAbilities = ConfigStore::has(WorldAbilityCatalog::class) ? ConfigStore::get(WorldAbilityCatalog::class) : null;
  ConfigStore::remove(WorldAbilityCatalog::class);
});
afterEach(function () {
  $this->priorAbilities === null ? ConfigStore::remove(WorldAbilityCatalog::class)
    : ConfigStore::put(WorldAbilityCatalog::class, $this->priorAbilities);
});

it('shares actual availability between movement, world predicates and acquired-power projection without saved duplication', function () {
  $catalog = new WorldAbilityCatalog([
    ['id' => 'dash', 'name' => 'Dash', 'description' => 'Move faster.', 'iconRole' => 'power.dash',
      'conditions' => [['type' => 'switch', 'name' => 'power_granted']]],
    ['id' => 'sense', 'name' => 'Sense passages', 'conditions' => [['type' => 'world_ability', 'name' => 'dash']]],
  ]);
  ConfigStore::put(WorldAbilityCatalog::class, $catalog);
  $state = new GameState();
  $dash = DashSettings::fromArray(['enabled' => true, 'conditions' => [['type' => 'world_ability', 'name' => 'dash']]]);
  $passage = \Ichiloto\Engine\Events\Triggers\EventTriggerFactory::create([
    'class' => \Ichiloto\Engine\Events\Triggers\PassageEventTrigger::class, 'marker' => 'P',
    'area' => ['cells' => [[2, 1]]], 'engagement' => 'facing', 'data' => ['reusable' => false, 'mode' => 'action'],
    'conditions' => [['type' => 'world_ability', 'name' => 'sense']], 'cue' => ['symbol' => '!', 'maxDistance' => 2],
  ], 'synthetic-cave');
  $passage->bind($state);
  expect($dash->isUnlocked($state))->toBeFalse()->and($catalog->getUnlockedAbilities($state))->toBe([])
    ->and($passage->isAvailable())->toBeFalse()
    ->and($passage->shouldRenderCue(new \Ichiloto\Engine\Core\Vector2(1, 1)))->toBeFalse();
  $state->setSwitch('power_granted', true); $before = $state->toArray();
  expect($dash->isUnlocked($state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([['type' => 'world_ability', 'name' => 'sense']], $state))->toBeTrue()
    ->and(array_keys($catalog->getUnlockedAbilities($state)))->toBe(['dash', 'sense'])
    ->and($catalog->getUnlockedAbilities($state)['dash'])->toBe($catalog->getAbility('dash'))
    ->and($passage->isAvailable())->toBeTrue()
    ->and($passage->shouldRenderCue(new \Ichiloto\Engine\Core\Vector2(1, 1)))->toBeTrue()
    ->and($state->toArray())->toBe($before);
  $state->setSwitch('power_granted', false);
  expect($dash->isUnlocked($state))->toBeFalse()->and($catalog->getUnlockedAbilities($state))->toBe([]);
});

it('uses an explicit detached catalog instead of a different installed project', function () {
  ConfigStore::put(WorldAbilityCatalog::class, new WorldAbilityCatalog([['id' => 'power', 'name' => 'Other power']]));
  $catalog = new WorldAbilityCatalog([
    ['id' => 'power', 'name' => 'Current power', 'conditions' => [['type' => 'switch', 'name' => 'current']]],
    ['id' => 'dependent', 'name' => 'Dependent', 'conditions' => [['type' => 'world_ability', 'name' => 'power']]],
  ]);
  $state = new GameState();
  expect(WorldConditionEvaluator::allHold([['type' => 'world_ability', 'name' => 'dependent']], $state,
    worldAbilities: $catalog))->toBeFalse();
  $state->setSwitch('current', true);
  expect($catalog->isUnlocked('dependent', $state))->toBeTrue();
});

it('refuses missing dependencies and every direct, indirect or negated dependency cycle before evaluation', function (array $entries) {
  expect(fn() => new WorldAbilityCatalog($entries))->toThrow(InvalidArgumentException::class);
})->with([
  [[['id' => 'a', 'name' => 'A', 'conditions' => [['type' => 'world_ability', 'name' => 'missing']]]]],
  [[['id' => 'a', 'name' => 'A', 'conditions' => [['type' => 'world_ability', 'name' => 'a']]]]],
  [[['id' => 'a', 'name' => 'A', 'conditions' => [['type' => ' world_ability ', 'name' => 'a', 'negate' => true]]]]],
  [[['id' => 'a', 'name' => 'A', 'conditions' => [['type' => 'world_ability', 'name' => 'b']]],
    ['id' => 'b', 'name' => 'B', 'conditions' => [['type' => 'world_ability', 'name' => 'a']]]]],
]);

it('reports invalid source records and missing runtime installation explicitly', function () {
  expect(fn() => WorldAbilityCatalog::getProjectCatalog())->toThrow(LogicException::class)
    ->and(fn() => new WorldAbilityCatalog([['id' => 'a', 'name' => 'A', 'typo' => true]]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new WorldAbilityCatalog([['id' => 'a', 'name' => 'A'], ['id' => 'a', 'name' => 'Again']]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new WorldAbilityCatalog([['id' => 'a', 'name' => 'A', 'conditions' => 'bad']]))->toThrow(InvalidArgumentException::class);
});

it('reads changed project source afresh while stable references keep their identity', function () {
  $root = createTestDirectory('world-abilities'); mkdir($root . '/Data');
  expect(WorldAbilityCatalog::fromProject($root)->getAbilities())->toBe([]);
  $file = $root . '/' . WorldAbilityCatalog::FILE;
  file_put_contents($file, '<?php return ' . var_export([['id' => 'power', 'name' => 'First']], true) . ';');
  expect(WorldAbilityCatalog::fromProject($root)->getAbility('power')->name)->toBe('First');
  file_put_contents($file, '<?php return ' . var_export([['id' => 'power', 'name' => 'Second',
    'conditions' => [['type' => 'switch', 'name' => 'later']]]], true) . ';');
  $next = WorldAbilityCatalog::fromProject($root);
  expect($next->getAbility('power')->name)->toBe('Second')->and($next->isUnlocked('power', new GameState()))->toBeFalse();
});

it('proves availability complements through ability definitions without flattening a negated conjunction', function () {
  $catalog = new WorldAbilityCatalog([
    ['id' => 'single', 'name' => 'Single', 'conditions' => [['type' => 'switch', 'name' => 's']]],
    ['id' => 'both', 'name' => 'Both', 'conditions' => [['type' => 'switch', 'name' => 's'], ['type' => 'switch', 'name' => 't']]],
  ]);
  expect(WorldConditionExclusion::proveExclusive([['type' => 'world_ability', 'name' => 'single']],
    [['type' => 'switch', 'name' => 's', 'value' => false]], $catalog))->toBeTrue()
    ->and(WorldConditionExclusion::proveExclusive([['type' => 'world_ability', 'name' => 'single', 'negate' => true]],
      [['type' => 'switch', 'name' => 's']], $catalog))->toBeTrue()
    ->and(WorldConditionExclusion::proveExclusive([['type' => 'world_ability', 'name' => 'both', 'negate' => true]],
      [['type' => 'switch', 'name' => 's']], $catalog))->toBeFalse();
});

it('keeps project registration read-only and scopes dependent evaluation results to one query', function () {
  $entries = [['id' => 'base', 'name' => 'Base', 'conditions' => [['type' => 'switch', 'name' => 'ready']]]];
  for ($i = 0; $i < 24; $i++) {
    $previous = $i === 0 ? 'base' : 'branch.' . ($i - 1);
    $entries[] = ['id' => 'branch.' . $i, 'name' => 'Branch ' . $i,
      'conditions' => [['type' => 'world_ability', 'name' => $previous], ['type' => 'world_ability', 'name' => 'base']]];
  }
  $catalog = new WorldAbilityCatalog($entries); $state = new GameState();
  ConfigStore::put(WorldAbilityCatalog::class, $catalog);
  expect(WorldAbilityCatalog::getProjectCatalog())->toBe($catalog)->and($catalog->has('base'))->toBeTrue()
    ->and($catalog->get('base'))->toBe($catalog->getAbility('base'))
    ->and(fn() => $catalog->set('base', null))->toThrow(LogicException::class)
    ->and(fn() => $catalog->persist())->toThrow(LogicException::class);
  expect($catalog->getUnlockedAbilities($state))->toBe([]);
  $state->setSwitch('ready', true);
  expect(count($catalog->getUnlockedAbilities($state)))->toBe(25);
  $state->setSwitch('ready', false);
  expect($catalog->isUnlocked('branch.23', $state))->toBeFalse()->and($catalog->getUnlockedAbilities($state))->toBe([]);
});

it('preserves quoted numeric resource identities through graph validation and lookup', function () {
  $catalog = new WorldAbilityCatalog([
    ['id' => '1', 'name' => 'First'],
    ['id' => '2', 'name' => 'Second', 'conditions' => [['type' => 'world_ability', 'name' => '1']]],
  ]);
  expect($catalog->getAbility('2')->id)->toBe('2')->and($catalog->isUnlocked('2', new GameState()))->toBeTrue();
});


it('selects positive consumer power references without turning negated requirements into first-use prompts', function () {
  $catalog = new WorldAbilityCatalog([['id' => 'a', 'name' => 'A'], ['id' => 'b', 'name' => 'B']]);
  $selected = $catalog->getRequiredAbilities([
    ['type' => 'world_ability', 'name' => 'a'], ['type' => 'world_ability', 'name' => 'a'],
    ['type' => 'world_ability', 'name' => 'b', 'negate' => true], ['type' => 'switch', 'name' => 'other'],
  ]);
  expect(array_map(fn($power) => $power->id, $selected))->toBe(['a']);
});

it('validates first-use source and reads old saves with no presentation facts', function () {
  foreach ([[], ['hints' => ['dash']], ['hints' => [['action' => '', 'label' => 'Run']]], ['effect' => '../outside']] as $source) {
    expect(fn() => new WorldAbilityCatalog([['id' => 'a', 'name' => 'A', 'firstUse' => $source]]))->toThrow(InvalidArgumentException::class);
  }
  $state = GameState::fromArray(['switches' => ['a' => true], 'worldAbilityPrompts' => ['a' => false, 'b' => 'true']]);
  expect($state->hasSeenWorldAbilityPrompt('a'))->toBeFalse()->and($state->hasSeenWorldAbilityPrompt('b'))->toBeFalse()
    ->and($state->getSwitch('a'))->toBeTrue();
});
