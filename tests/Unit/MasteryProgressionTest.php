<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Progression\Mastery\MasteryProgress;
use Ichiloto\Engine\Progression\Mastery\MasteryProgression;
use Ichiloto\Engine\Progression\Mastery\MasteryRefusal;
use Ichiloto\Engine\Progression\Mastery\MasteryRefusalReason;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Entities\Party;

/** A synthetic map: a free starting skill, two level-2 skills, and one that builds on the first of them and stays a secret. */
function masteryFixture(): array
{
  $skills = SkillCatalog::fromSkills([
    'Skills/start.php' => new SpecialSkill('Start Cut', '', '', 2, 0, effects: [new HPDamageSkillEffect('1')]),
    'Skills/spark.php' => new MagicSkill('Spark', '', '', 4, 0),
    'Skills/guard.php' => new SpecialSkill('Guard Up', '', '', 3, 0),
    'Skills/storm.php' => new MagicSkill('Storm', '', '', 9, 0),
  ]);
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [
    ['id' => 'start', 'skill' => 'Start Cut', 'region' => 'blades', 'cost' => 0],
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 50, 'opensAtLevel' => 2],
    ['id' => 'guard', 'skill' => 'Guard Up', 'region' => 'blades', 'cost' => 40, 'opensAtLevel' => 2],
    ['id' => 'storm', 'skill' => 'Storm', 'region' => 'storms', 'cost' => 80, 'opensAtLevel' => 2, 'requires' => ['spark'], 'hidden' => true],
  ]], ['blades', 'storms']);

  return [new MasteryProgression($map, $skills), new Character('Hero', 0, new Stats())];
}

/** The EXP the character needs, from where they are, to reach a level. */
function experienceToLevel(Character $hero, int $level): int
{
  return max(0, $hero->getLevelExperienceThresholds()[$level] - $hero->currentExp);
}

/** @return array<string, string> Each node's state, by id. */
function masteryStates(MasteryProgression $mastery, Character $hero): array
{
  $states = [];
  foreach ($mastery->describe($hero) as $status) {
    $states[$status->node->id] = $status->state->value;
  }

  return $states;
}

/** The reason a learn attempt is refused. */
function masteryRefusal(MasteryProgression $mastery, Character $hero, string $node): ?MasteryRefusalReason
{
  try {
    $mastery->learn($hero, $node);
  } catch (MasteryRefusal $refusal) {
    return $refusal->reason;
  }

  return null;
}

it('learns a free node as soon as it opens, without spending points', function () {
  [$mastery, $hero] = masteryFixture();
  $hero->mastery->points = 30;

  $first = $mastery->reconcile($hero);

  expect(array_map(static fn($skill): string => $skill->name, $first->learned))->toBe(['Start Cut'])
    ->and($hero->abilityBook->getLearnedAbilities()[0]->name)->toBe('Start Cut')
    ->and($hero->mastery->points)->toBe(30)
    ->and(masteryStates($mastery, $hero))->toBe(['start' => 'learned', 'spark' => 'locked', 'guard' => 'locked', 'storm' => 'locked']);
});

it('spends a node\'s price from the total and learns it at once, in any order the player picks', function () {
  [$mastery, $hero] = masteryFixture();
  $hero->addExperience(experienceToLevel($hero, 2));
  $mastery->reconcile($hero);
  $hero->mastery->points = 100;

  $guard = $mastery->learn($hero, 'guard');
  $spark = $mastery->learn($hero, 'spark');

  expect(array_map(static fn($skill): string => $skill->name, $guard->learned))->toBe(['Guard Up'])
    ->and(array_map(static fn($skill): string => $skill->name, $spark->learned))->toBe(['Spark'])
    ->and($hero->mastery->points)->toBe(10)
    ->and($hero->spellbook->getLearnedSpells()[0]->name)->toBe('Spark')
    ->and(masteryStates($mastery, $hero))->toBe(['start' => 'learned', 'spark' => 'learned', 'guard' => 'learned', 'storm' => 'open']);
});

it('refuses a node that costs more than the total, is locked, or is already learned, spending nothing', function () {
  [$mastery, $hero] = masteryFixture();
  $mastery->reconcile($hero);
  $hero->mastery->points = 45;

  expect(masteryRefusal($mastery, $hero, 'spark'))->toBe(MasteryRefusalReason::LOCKED);
  $hero->addExperience(experienceToLevel($hero, 2));

  expect(masteryRefusal($mastery, $hero, 'spark'))->toBe(MasteryRefusalReason::SHORT)
    ->and(masteryRefusal($mastery, $hero, 'start'))->toBe(MasteryRefusalReason::LEARNED)
    ->and(masteryRefusal($mastery, $hero, 'storm'))->toBe(MasteryRefusalReason::LOCKED)
    ->and(masteryRefusal($mastery, $hero, 'nowhere'))->toBe(MasteryRefusalReason::UNKNOWN)
    ->and($hero->mastery->points)->toBe(45)
    ->and($mastery->describe($hero)[1]->affordable)->toBeFalse()
    ->and($mastery->describe($hero)[2]->learnable)->toBeTrue();
});

it('says why a node is locked, and keeps a hidden one a secret only while it is locked', function () {
  [$mastery, $hero] = masteryFixture();
  $mastery->reconcile($hero);
  $locked = $mastery->describe($hero)[3];
  $hero->addExperience(experienceToLevel($hero, 2));
  $waiting = $mastery->describe($hero)[3];
  $hero->mastery->points = 50;
  $mastery->learn($hero, 'spark');
  $opened = $mastery->describe($hero)[3];

  expect($locked->state)->toBe(MasteryNodeState::LOCKED)
    ->and($locked->opensAtLevel)->toBe(2)
    ->and($locked->missing)->toBe(['spark'])
    ->and($locked->secret)->toBeTrue()
    ->and($waiting->opensAtLevel)->toBeNull()
    ->and($waiting->missing)->toBe(['spark'])
    ->and($opened->state)->toBe(MasteryNodeState::OPEN)
    ->and($opened->secret)->toBeFalse();
});

it('gives every travelling member the points a reward carries, alongside its EXP', function () {
  $party = new Party();
  foreach (['Hero', 'Ally', 'Reserve'] as $name) { $party->addMember(new Character($name, 0, new Stats())); }

  $results = ExperienceAwarder::awardParty($party, 30, 7);

  expect(array_map(static fn($member): int => $member->mastery->points, $party->members->toArray()))->toBe([7, 7, 7])
    ->and(array_map(static fn($result): int => $result->skillPointsAwarded, $results))->toBe([7, 7, 7])
    ->and(fn() => ExperienceAwarder::award(new Character('Hero', 0, new Stats()), 0, -1))->toThrow(InvalidArgumentException::class);
});

it('counts skills an old save already knows as learned and keeps points through a save', function () {
  [$mastery, $hero] = masteryFixture();
  $hero->addExperience(experienceToLevel($hero, 2));
  $hero->learnSkill(new MagicSkill('Spark', '', '', 4, 0));
  $mastery->reconcile($hero);
  $hero->mastery->points = 25;

  $restored = MasteryProgress::fromArray(json_decode(json_encode($hero->mastery), true));

  expect($hero->mastery->hasLearned('spark'))->toBeTrue()
    ->and($restored->learned)->toBe(['spark', 'start'])
    ->and($restored->points)->toBe(25)
    ->and(json_decode(json_encode(new MasteryProgress()), true))->toBe([]);
});

it('refuses EXP progress saved before Mastery spent points, so no content migration can be skipped silently', function () {
  expect(fn() => MasteryProgress::fromArray(['studying' => 'spark', 'progress' => ['spark' => 10], 'banked' => 5, 'learned' => ['start']]))
    ->toThrow(InvalidArgumentException::class, 'content migration');
});

it('refuses a map whose requirements loop, repeat a skill, or name an unknown region or node', function (array $nodes, string $message) {
  expect(fn() => MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => $nodes], ['blades']))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'loop' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'requires' => ['b']], ['id' => 'b', 'skill' => 'B', 'region' => 'blades', 'requires' => ['a']]], 'in a loop'],
  'same skill twice' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades'], ['id' => 'b', 'skill' => 'A', 'region' => 'blades']], 'more than one node'],
  'unknown region' => [[['id' => 'a', 'skill' => 'A', 'region' => 'storms']], 'unknown region'],
  'unknown requirement' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'requires' => ['z']]], 'unknown node z'],
  'negative cost' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'cost' => -1]], 'less than 0 points'],
  'study order from before Mastery spent points' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'order' => 1]], 'unknown key "order"'],
]);

it('places unplaced nodes down the middle of their region in the order the map lists them, and authored ones where the author put them', function () {
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [
    ['id' => 'a', 'skill' => 'A', 'region' => 'blades'],
    ['id' => 'b', 'skill' => 'B', 'region' => 'blades'],
    ['id' => 'c', 'skill' => 'C', 'region' => 'storms', 'position' => ['x' => 0.9, 'y' => 0.1]],
  ]], ['blades', 'storms', 'wards', 'guards']);
  $places = \Ichiloto\Engine\Progression\Mastery\MasteryLayout::place($map, ['blades', 'storms', 'wards', 'guards']);

  expect($places['a'])->toBe(['x' => 0.25, 'y' => 1 / 6, 'placed' => false])
    ->and($places['b'])->toBe(['x' => 0.25, 'y' => 2 / 6, 'placed' => false])
    ->and($places['c'])->toBe(['x' => 0.9, 'y' => 0.1, 'placed' => true]);
});
