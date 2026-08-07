<?php

use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonWielderPolicy;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;

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
