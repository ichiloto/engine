<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Roles\ExperienceCurveGenerator;
use Ichiloto\Engine\Entities\Roles\SkillToLearn;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Progression\ProgressionSettings;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Util\Stores\ClassStore;

beforeEach(function () {
  $this->previousProgression = ConfigStore::has(ProgressionSettings::class)
    ? ConfigStore::get(ProgressionSettings::class) : null;
  $this->previousItems = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;
  $this->previousClasses = new ReflectionProperty(ClassStore::class, 'definitions')->getValue();
  ConfigStore::remove(ProgressionSettings::class);
});

afterEach(function () {
  ConfigStore::remove(ProgressionSettings::class);
  if ($this->previousProgression !== null) {
    ConfigStore::put(ProgressionSettings::class, $this->previousProgression);
  }
  ConfigStore::remove(ItemStore::class);
  if ($this->previousItems !== null) {
    ConfigStore::put(ItemStore::class, $this->previousItems);
  }
  new ReflectionProperty(ClassStore::class, 'definitions')->setValue(null, $this->previousClasses);
});

function progressionTestCharacter(string $name): Character
{
  $character = new Character($name, 0, new Stats(currentHp: 100, currentMp: 30));
  $ability = new SpecialSkill('Role Ability', '', '', 0, 0);
  $magic = new MagicSkill('Role Magic', '', '', 0, 0);
  $role = new CharacterRole(
    $character,
    'Tester',
    new ExperienceCurveGenerator(),
    [
      new SkillToLearn(2, $ability),
      new SkillToLearn(4, $magic),
    ],
  );

  new ReflectionProperty(Character::class, 'role')->setValue($character, $role);
  new ReflectionMethod(Character::class, 'calculateLevelExpThresholds')->invoke($character);

  return $character;
}

it('grants every crossed role skill and reports magic separately', function () {
  $character = progressionTestCharacter('Kaelion');
  $character->stats->currentHp = 1;
  $character->stats->currentMp = 1;
  $result = ExperienceAwarder::award($character, 10000);

  expect($result->oldLevel)->toBe(1)
    ->and($result->newLevel)->toBeGreaterThanOrEqual(4)
    ->and($character->stats->currentHp)->toBe($character->stats->totalHp)
    ->and($character->stats->currentMp)->toBe($character->stats->totalMp)
    ->and($result->learnedAbilities)->toBe(['Role Ability'])
    ->and($result->learnedMagic)->toBe(['Role Magic'])
    ->and(array_column($character->abilityBook->getLearnedAbilities(), 'name'))->toContain('Role Ability')
    ->and(array_column($character->spellbook->getLearnedSpells(), 'name'))->toContain('Role Magic');
});

it('preserves current resources when an experience award does not level up', function () {
  $character = progressionTestCharacter('Kaelion');
  $character->stats->currentHp = 7;
  $character->stats->currentMp = 3;

  $result = ExperienceAwarder::award($character, 1);

  expect($result->levelledUp())->toBeFalse()
    ->and($character->stats->currentHp)->toBe(7)
    ->and($character->stats->currentMp)->toBe(3);
});

it('awards the full travelling roster and reconciles idempotently', function () {
  $party = new Party();

  foreach (['A', 'B', 'C', 'Reserve'] as $name) {
    $character = progressionTestCharacter($name);
    $character->stats->currentHp = 1;
    $character->stats->currentMp = 0;
    $party->addMember($character);
  }

  $results = ExperienceAwarder::awardParty($party, 10000);
  $secondPass = array_map(
    ExperienceAwarder::reconcileAutomaticRoleSkills(...),
    $party->members->toArray(),
  );

  expect($results)->toHaveCount(4)
    ->and(array_map(static fn($result): array => $result->learnedSkills(), $results))
    ->each->toBe(['Role Ability', 'Role Magic'])
    ->and(array_map(static fn($result): array => $result->learnedSkills(), $secondPass))
    ->each->toBe([])
    ->and(array_map(
      static fn(Character $character): bool =>
        $character->stats->currentHp === $character->stats->totalHp
        && $character->stats->currentMp === $character->stats->totalMp,
      $party->members->toArray(),
    ))->each->toBeTrue();
});

it('captures immutable progression facts before and after the award', function () {
  $character = progressionTestCharacter('Snapshot');
  $thresholds = $character->getLevelExperienceThresholds();
  $result = ExperienceAwarder::award($character, $thresholds[4]);
  $before = $result->before;
  $after = $result->after;
  $originalStats = $after->stats;
  $character->addExperience($thresholds[5]);
  $character->stats->attack = 999;

  expect($before->experience)->toBe(0)
    ->and($before->level)->toBe(1)
    ->and($after->experience)->toBe($thresholds[4])
    ->and($after->level)->toBe(4)
    ->and($after->stats)->toBe($originalStats)
    ->and($after->progressAt($thresholds[3]))->toMatchArray(['level' => 3, 'current' => 0, 'maximum' => false])
    ->and($after->progressAt($thresholds[4] - 1)['level'])->toBe(3)
    ->and(array_column($result->learnedDetails, 'kind'))->toBe(['Ability', 'Magic']);
});

it('reports capped progression without a fictional next-level threshold', function () {
  $character = progressionTestCharacter('Capped');
  $thresholds = $character->getLevelExperienceThresholds();
  $result = ExperienceAwarder::award($character, $thresholds[$character->maxLevel]);
  expect($result->after->progressAt($result->after->experience))->toMatchArray([
    'level' => $character->maxLevel, 'maximum' => true, 'needed' => 0, 'ratio' => 1.0,
  ]);
});

it('applies the project recovery policy without changing growth, equipment, skills, AP or states', function (bool $restore) {
  ConfigStore::put(ProgressionSettings::class, ProgressionSettings::fromArray(['restoreVitalsOnLevelUp' => $restore]));
  $character = progressionTestCharacter('Policy Hero');
  $gear = new Weapon('Fixture Blade', '', '!', 1, parameterChanges: new ParameterChanges(totalHp: 50, totalMp: 10));
  $slot = $character->equipment[0];
  $slot->equipment = $gear;
  new ReflectionMethod(Character::class, 'adjustStatTotals')->invoke($character);
  $character->stats->currentHp = 7;
  $character->stats->currentMp = 3;
  $character->stats->totalAp = 5;
  $character->stats->currentAp = 2;
  $character->addState(new State('fixture-state', 'Fixture State', durationTurns: 4));
  $beforeHp = $character->stats->totalHp;
  $beforeMp = $character->stats->totalMp;
  $experience = $character->getLevelExperienceThresholds()[4];

  $result = ExperienceAwarder::award($character, $experience);

  expect($result->oldLevel)->toBe(1)
    ->and($result->newLevel)->toBe(4)
    ->and($character->currentExp)->toBe($experience)
    ->and($character->stats->totalHp)->toBeGreaterThan($beforeHp)
    ->and($character->stats->totalMp)->toBeGreaterThan($beforeMp)
    ->and($character->stats->currentHp)->toBe($restore ? $character->stats->totalHp : 7)
    ->and($character->stats->currentMp)->toBe($restore ? $character->stats->totalMp : 3)
    ->and($character->stats->currentAp)->toBe(2)
    ->and($character->states[0]->remainingTurns)->toBe(4)
    ->and($character->hasState('fixture-state'))->toBeTrue()
    ->and($character->equipment[0]->equipment)->toBe($gear)
    ->and($result->learnedAbilities)->toBe(['Role Ability'])
    ->and($result->learnedMagic)->toBe(['Role Magic']);
})->with([true, false]);

it('preserves a knocked-out travelling member when recovery is disabled', function (bool $restore) {
  ConfigStore::put(ProgressionSettings::class, new ProgressionSettings($restore));
  $party = new Party();
  foreach (['Active', 'Reserve'] as $name) {
    $character = progressionTestCharacter($name);
    $character->stats->currentHp = 0;
    $character->stats->currentMp = 0;
    $party->addMember($character);
  }

  $results = ExperienceAwarder::awardParty($party, 10000);
  expect($results)->toHaveCount(2);
  foreach ($party->members->toArray() as $character) {
    expect($character->level)->toBeGreaterThanOrEqual(4)
      ->and($character->stats->currentHp)->toBe($restore ? $character->stats->totalHp : 0)
      ->and($character->stats->currentMp)->toBe($restore ? $character->stats->totalMp : 0);
  }
})->with([true, false]);

it('never refills resources without a level gain under either policy', function (bool $restore) {
  ConfigStore::put(ProgressionSettings::class, new ProgressionSettings($restore));
  $character = progressionTestCharacter('Unchanged');
  $character->stats->currentHp = 7;
  $character->stats->currentMp = 3;

  foreach ([0, 1] as $experience) {
    expect(ExperienceAwarder::award($character, $experience)->levelledUp())->toBeFalse()
      ->and($character->stats->currentHp)->toBe(7)
      ->and($character->stats->currentMp)->toBe(3);
  }
  ExperienceAwarder::reconcileAutomaticRoleSkills($character);
  expect($character->stats->currentHp)->toBe(7)->and($character->stats->currentMp)->toBe(3);
})->with([true, false]);

it('leaves damaged save hydration and equipment recalculation outside the recovery policy', function (bool $restore) {
  ConfigStore::put(ProgressionSettings::class, new ProgressionSettings($restore));
  new ReflectionProperty(ClassStore::class, 'definitions')->setValue(null, [
    'fixture role' => ['name' => 'Fixture Role', 'parameterCurves' => [
      'totalHp' => ['baseValue' => 100], 'totalMp' => ['baseValue' => 30],
    ]],
  ]);
  $character = new Character('Saved Hero', 0, new Stats(currentHp: 100, currentMp: 30));
  $character->applyClass('Fixture Role');
  $gear = new Weapon('Saved Blade', '', '!', 1, parameterChanges: new ParameterChanges(totalHp: 50, totalMp: 10), id: 'fixture.saved-blade');
  $items = new ReflectionClass(ItemStore::class)->newInstanceWithoutConstructor();
  $items->set($gear->id, $gear);
  ConfigStore::put(ItemStore::class, $items);
  $slot = $character->equipment[0];
  $slot->equipment = $gear;
  new ReflectionMethod(Character::class, 'adjustStatTotals')->invoke($character);
  $character->stats->currentHp = 7;
  $character->stats->currentMp = 3;
  $saved = serialize($character);
  $loaded = unserialize($saved);

  expect($loaded)->toBeInstanceOf(Character::class)
    ->and($loaded->stats->currentHp)->toBe(7)
    ->and($loaded->stats->currentMp)->toBe(3)
    ->and($loaded->equipment[0]->equipment->name)->toBe('Saved Blade')
    ->and($loaded->stats->totalHp)->toBe($character->stats->totalHp)
    ->and($loaded->stats->totalMp)->toBe($character->stats->totalMp)
    ->and(array_key_exists('progression', $loaded->toArray()))->toBeFalse();
  $slot = $loaded->equipment[0];
  $slot->equipment = null;
  new ReflectionMethod(Character::class, 'adjustStatTotals')->invoke($loaded);
  expect($loaded->stats->currentHp)->toBe(7)->and($loaded->stats->currentMp)->toBe(3);
})->with([true, false]);

it('rejects invalid award inputs before modifying character progression', function () {
  $character = progressionTestCharacter('Invalid');
  $before = serialize($character);
  expect(fn() => ExperienceAwarder::award($character, -1))->toThrow(InvalidArgumentException::class)
    ->and(serialize($character))->toBe($before);
  ConfigStore::put(ProgressionSettings::class, new SceneAudioConfigStub(['restoreVitalsOnLevelUp' => false]));
  expect(fn() => ExperienceAwarder::award($character, 10000))->toThrow(InvalidArgumentException::class, 'validated project contract')
    ->and(serialize($character))->toBe($before);
});
