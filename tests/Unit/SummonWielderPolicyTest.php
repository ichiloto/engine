<?php

use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonWielderPolicy;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Exceptions\SummonAssignmentException;

function makeSummoner(string $name, string $roleName = 'Hero'): Character
{
  $character = new Character($name, 0, new Stats());
  $character->role = new CharacterRole($character, $roleName);

  return $character;
}

function makeSummonDefinition(array $wielders): SummonCutsceneDefinition
{
  return SummonCutsceneDefinition::fromArrays(
    [
      'id' => 'test-summon',
      'name' => 'Test Summon',
      'wielders' => $wielders,
    ],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );
}

it('has no wielder policy when the data declares none', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    ['id' => 'open-summon', 'name' => 'Open Summon'],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );

  expect($definition->wielders)->toBeNull();
});

it('preserves open behavior when availability is omitted', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    ['id' => 'open-summon', 'name' => 'Open Summon'],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );

  expect($definition->availability)->toBeNull()
    ->and($definition->isAvailable(new GameState()))->toBeTrue()
    ->and($definition->toDataArray())->not->toHaveKey('availability');
});

it('locks a summon until all declared world conditions hold', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    [
      'id' => 'gated-summon',
      'name' => 'Gated Summon',
      'availability' => [
        'conditions' => [
          ['type' => 'event', 'name' => 'summon_unlocked'],
          ['type' => 'switch', 'name' => 'processor_ready'],
        ],
      ],
    ],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );
  $state = new GameState();

  expect($definition->isAvailable($state))->toBeFalse();
  $state->recordStoryEvent('summon_unlocked');
  expect($definition->isAvailable($state))->toBeFalse();
  $state->setSwitch('processor_ready');
  expect($definition->isAvailable($state))->toBeTrue();
});

it('fails closed for malformed and unknown availability conditions', function (mixed $availability) {
  $definition = SummonCutsceneDefinition::fromArrays(
    ['id' => 'unsafe', 'name' => 'Unsafe', 'availability' => $availability],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );

  expect($definition->availability?->isValid())->toBeFalse()
    ->and($definition->isAvailable(new GameState()))->toBeFalse();
})->with([
  'scalar block' => ['not-an-array'],
  'missing conditions' => [[]],
  'empty conditions' => [['conditions' => []]],
  'unknown type' => [['conditions' => [['type' => 'moon', 'name' => 'full']]]],
  'missing name' => [['conditions' => [['type' => 'event']]]],
  'non-string type' => [['conditions' => [['type' => ['event'], 'name' => 'unlock']]]],
  'non-string name' => [['conditions' => [['type' => 'event', 'name' => ['unlock']]]]],
  'invalid variable operator' => [['conditions' => [['type' => 'variable', 'name' => 'charge', 'op' => 'approximately']]]],
  'invalid item quantity' => [['conditions' => [['type' => 'item', 'name' => 'Sigil', 'quantity' => 0]]]],
  'invalid quest status' => [['conditions' => [['type' => 'quest', 'name' => 'pilgrimage', 'status' => 'forgotten']]]],
]);

it('allows any character when the mode is all', function () {
  $policy = SummonWielderPolicy::fromArray(['mode' => 'all']);

  expect($policy->allowsCharacter(makeSummoner('Anyone')))->toBeTrue();
});

it('restricts eligibility to named characters', function () {
  $policy = SummonWielderPolicy::fromArray([
    'mode' => 'characters',
    'characters' => ['Yuna'],
  ]);

  expect($policy->allowsCharacter(makeSummoner('Yuna')))->toBeTrue()
    ->and($policy->allowsCharacter(makeSummoner('Tidus')))->toBeFalse();
});

it('restricts eligibility to named roles', function () {
  $policy = SummonWielderPolicy::fromArray([
    'mode' => 'roles',
    'roles' => ['Oracle'],
  ]);

  expect($policy->allowsCharacter(makeSummoner('Liora', 'Oracle')))->toBeTrue()
    ->and($policy->allowsCharacter(makeSummoner('Kaelion', 'Vanguard')))->toBeFalse();
});

it('enforces exclusive tenancy across the party', function () {
  $party = new Party();
  $first = makeSummoner('One');
  $second = makeSummoner('Two');
  $party->addMember($first);
  $party->addMember($second);

  $definition = makeSummonDefinition(['mode' => 'all', 'tenancy' => 'exclusive']);

  expect($party->assignSummon($definition, $first))->toBeTrue()
    ->and($party->canAssignSummon($definition, $second))->toBeFalse()
    ->and($party->assignSummon($definition, $second))->toBeFalse();

  // Releasing the summon frees it for another member.
  $party->unassignSummon($definition->id, $first);
  expect($party->assignSummon($definition, $second))->toBeTrue();
});

it('reassigns exclusive ownership atomically after validation', function () {
  $party = new Party();
  $first = makeSummoner('One');
  $second = makeSummoner('Two');
  $party->addMember($first);
  $party->addMember($second);
  $definition = makeSummonDefinition(['mode' => 'all', 'tenancy' => 'exclusive']);

  expect($party->assignSummon($definition, $first))->toBeTrue()
    ->and($party->reassignSummon($definition, $second))->toBeTrue()
    ->and($first->hasSummon($definition->id))->toBeFalse()
    ->and($second->hasSummon($definition->id))->toBeTrue()
    ->and($party->getSummonHolders($definition->id))->toBe([$second]);
});

it('rejects duplicate exclusive ownership as controlled corrupt state', function () {
  $party = new Party();
  $first = makeSummoner('One');
  $second = makeSummoner('Two');
  $party->addMember($first);
  $party->addMember($second);
  $definition = makeSummonDefinition(['mode' => 'all', 'tenancy' => 'exclusive']);
  $first->assignSummon($definition->id);
  $second->assignSummon($definition->id);

  expect(fn() => $party->assertSummonAssignments([$definition]))
    ->toThrow(SummonAssignmentException::class, 'multiple holders');
});

it('preserves a legal locked assignment but prevents assignment before unlock', function () {
  $party = new Party();
  $character = makeSummoner('One');
  $party->addMember($character);
  $definition = SummonCutsceneDefinition::fromArrays(
    [
      'id' => 'locked-summon',
      'name' => 'Locked Summon',
      'wielders' => ['mode' => 'all', 'tenancy' => 'exclusive'],
      'availability' => ['conditions' => [['type' => 'event', 'name' => 'summon_unlocked']]],
    ],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );
  $state = new GameState();

  expect($party->assignSummon($definition, $character, $state))->toBeFalse();
  $character->assignSummon($definition->id);
  $party->assertSummonAssignments([$definition]);
  expect($character->hasSummon($definition->id))->toBeTrue();
  $state->recordStoryEvent('summon_unlocked');
  expect($party->canAssignSummon($definition, $character, $state))->toBeTrue();
});

it('fails invalid wielder modes and tenancy closed', function (array $wielders) {
  $policy = SummonWielderPolicy::fromAuthored($wielders);

  expect($policy->isValid())->toBeFalse()
    ->and($policy->allowsCharacter(makeSummoner('Anyone')))->toBeFalse();
})->with([
  'mode' => [['mode' => 'chosen_one', 'tenancy' => 'exclusive']],
  'tenancy' => [['mode' => 'all', 'tenancy' => 'unlimited']],
  'empty roles' => [['mode' => 'roles', 'roles' => [], 'tenancy' => 'exclusive']],
  'empty characters' => [['mode' => 'characters', 'characters' => [], 'tenancy' => 'exclusive']],
  'malformed roles' => [['mode' => 'roles', 'roles' => ['Summoner', 42], 'tenancy' => 'exclusive']],
  'malformed characters' => [['mode' => 'characters', 'characters' => ['Luna', ['Sol']], 'tenancy' => 'exclusive']],
]);

it('does not reinterpret a malformed declared policy as open access', function (mixed $wielders) {
  $definition = SummonCutsceneDefinition::fromArrays(
    ['id' => 'unsafe', 'name' => 'Unsafe', 'wielders' => $wielders],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );

  expect($definition->wielders)->not->toBeNull()
    ->and($definition->wielders?->isValid())->toBeFalse()
    ->and($definition->wielders?->allowsCharacter(makeSummoner('Anyone')))->toBeFalse();
})->with([
  'scalar policy' => ['everyone'],
  'non-string mode' => [['mode' => ['all']]],
  'non-string tenancy' => [['tenancy' => ['shared']]],
]);

it('allows shared tenancy for multiple members at once', function () {
  $party = new Party();
  $first = makeSummoner('One');
  $second = makeSummoner('Two');
  $party->addMember($first);
  $party->addMember($second);

  $definition = makeSummonDefinition(['mode' => 'all', 'tenancy' => 'shared']);

  expect($party->assignSummon($definition, $first))->toBeTrue()
    ->and($party->assignSummon($definition, $second))->toBeTrue()
    ->and($party->getSummonHolders($definition->id))->toHaveCount(2);
});

it('refuses assignment to ineligible characters', function () {
  $party = new Party();
  $summoner = makeSummoner('Yuna');
  $other = makeSummoner('Tidus');
  $party->addMember($summoner);
  $party->addMember($other);

  $definition = makeSummonDefinition([
    'mode' => 'characters',
    'characters' => ['Yuna'],
    'tenancy' => 'exclusive',
  ]);

  expect($party->assignSummon($definition, $other))->toBeFalse()
    ->and($party->assignSummon($definition, $summoner))->toBeTrue();
});

it('round-trips summon assignments through character serialization', function () {
  $character = makeSummoner('One');
  $character->assignSummon('ifrit');
  $character->assignSummon('donna');

  $data = $character->toArray();
  expect($data['summons'])->toBe(['ifrit', 'donna']);

  $restored = Character::fromArray([
    'name' => 'One',
    'currentExp' => 0,
    'stats' => [
      'currentHp' => 100, 'currentMp' => 10, 'currentAp' => 0,
      'totalHp' => 100, 'totalMp' => 10, 'totalAp' => 0,
      'attack' => 10, 'defence' => 10, 'magicAttack' => 10, 'magicDefence' => 10,
      'speed' => 10, 'grace' => 10, 'evasion' => 10,
    ],
    'summons' => $data['summons'],
  ]);

  expect($restored->hasSummon('ifrit'))->toBeTrue()
    ->and($restored->hasSummon('donna'))->toBeTrue()
    ->and($restored->hasSummon('torro'))->toBeFalse();
});

it('keeps assignment idempotent and case-insensitive', function () {
  $character = makeSummoner('One');
  $character->assignSummon('Ifrit');
  $character->assignSummon('ifrit');

  expect($character->summons)->toBe(['ifrit'])
    ->and($character->hasSummon('IFRIT'))->toBeTrue();

  $character->unassignSummon('Ifrit');
  expect($character->summons)->toBe([]);
});

it('rejects malformed summon ownership during character hydration', function (mixed $summons) {
  $character = makeSummoner('One');
  $data = $character->__serialize();
  $data['summons'] = $summons;
  $restored = makeSummoner('Restored');

  expect(fn() => $restored->__unserialize($data))
    ->toThrow(SummonAssignmentException::class);
})->with([
  'scalar container' => ['ifrit'],
  'associative container' => [['primary' => 'ifrit']],
  'non-string id' => [[42]],
  'empty id' => [['  ']],
  'normalized duplicate' => [['Ifrit', 'ifrit']],
]);
