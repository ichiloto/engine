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

/** A synthetic map: a free starting skill, two level-2 skills, and one that builds on the first of them. */
function masteryFixture(): array
{
  $skills = SkillCatalog::fromSkills([
    'Skills/start.php' => new SpecialSkill('Start Cut', '', '', 2, 0, effects: [new HPDamageSkillEffect('1')]),
    'Skills/spark.php' => new MagicSkill('Spark', '', '', 4, 0),
    'Skills/guard.php' => new SpecialSkill('Guard Up', '', '', 3, 0),
    'Skills/storm.php' => new MagicSkill('Storm', '', '', 9, 0),
  ]);
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [
    ['id' => 'start', 'skill' => 'Start Cut', 'region' => 'blades', 'cost' => 0, 'order' => 1],
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 50, 'opensAtLevel' => 2, 'order' => 2],
    ['id' => 'guard', 'skill' => 'Guard Up', 'region' => 'blades', 'cost' => 40, 'opensAtLevel' => 2, 'order' => 3],
    ['id' => 'storm', 'skill' => 'Storm', 'region' => 'storms', 'cost' => 80, 'opensAtLevel' => 2, 'requires' => ['spark'], 'order' => 4],
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

it('learns a free node at once and banks EXP earned while nothing is open', function () {
  [$mastery, $hero] = masteryFixture();

  $first = $mastery->advance($hero, 30);

  expect(array_map(static fn($skill): string => $skill->name, $first->learned))->toBe(['Start Cut'])
    ->and($hero->abilityBook->getLearnedAbilities()[0]->name)->toBe('Start Cut')
    ->and($hero->mastery->banked)->toBe(30)
    ->and(masteryStates($mastery, $hero))->toBe(['start' => 'learned', 'spark' => 'locked', 'guard' => 'locked', 'storm' => 'locked']);
});

it('puts banked and new EXP into the default order once nodes open, learning each it completes', function () {
  [$mastery, $hero] = masteryFixture();
  $mastery->advance($hero, 0);
  $hero->addExperience(experienceToLevel($hero, 2));
  $hero->mastery->banked = 70;

  $advance = $mastery->advance($hero, 0);

  expect(array_map(static fn($skill): string => $skill->name, $advance->learned))->toBe(['Spark'])
    ->and($hero->spellbook->getLearnedSpells()[0]->name)->toBe('Spark')
    ->and($hero->mastery->getProgress('guard'))->toBe(20)
    ->and($advance->studying)->toBe('guard')
    ->and(masteryStates($mastery, $hero))->toBe(['start' => 'learned', 'spark' => 'learned', 'guard' => 'studying', 'storm' => 'open']);
});

it('lets the player study another open node, keeping the progress each node already has', function () {
  [$mastery, $hero] = masteryFixture();
  $hero->addExperience(experienceToLevel($hero, 2));
  $mastery->advance($hero, 30);

  $mastery->choose($hero, 'guard');
  $mastery->advance($hero, 25);

  expect($hero->mastery->getProgress('spark'))->toBe(30)
    ->and($hero->mastery->getProgress('guard'))->toBe(25)
    ->and(masteryStates($mastery, $hero)['guard'])->toBe('studying')
    ->and(fn() => $mastery->choose($hero, 'storm'))->toThrow(InvalidArgumentException::class, 'cannot study Storm yet')
    ->and(fn() => $mastery->choose($hero, 'start'))->toThrow(InvalidArgumentException::class, 'already knows Start Cut');
});

it('says why a node is locked: the level it waits for, or what it builds on', function () {
  [$mastery, $hero] = masteryFixture();
  $mastery->advance($hero, 0);
  $locked = $mastery->describe($hero)[3];
  $hero->addExperience(experienceToLevel($hero, 2));
  $waiting = $mastery->describe($hero)[3];

  expect($locked->state)->toBe(MasteryNodeState::LOCKED)
    ->and($locked->opensAtLevel)->toBe(2)
    ->and($locked->missing)->toBe(['spark'])
    ->and($waiting->opensAtLevel)->toBeNull()
    ->and($waiting->missing)->toBe(['spark'])
    ->and($waiting->remaining)->toBe(80);
});

it('counts skills an old save already knows as learned and keeps progress through a save', function () {
  [$mastery, $hero] = masteryFixture();
  $hero->addExperience(experienceToLevel($hero, 2));
  $hero->learnSkill(new MagicSkill('Spark', '', '', 4, 0));
  $mastery->advance($hero, 10);

  $restored = MasteryProgress::fromArray(json_decode(json_encode($hero->mastery), true));

  expect($hero->mastery->hasLearned('spark'))->toBeTrue()
    ->and($restored->learned)->toBe(['spark', 'start'])
    ->and($restored->getProgress('guard'))->toBe(10)
    ->and(json_decode(json_encode(new MasteryProgress()), true))->toBe([]);
});

it('refuses a map whose requirements loop, repeat a skill, or name an unknown region or node', function (array $nodes, string $message) {
  expect(fn() => MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => $nodes], ['blades']))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'loop' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'requires' => ['b']], ['id' => 'b', 'skill' => 'B', 'region' => 'blades', 'requires' => ['a']]], 'in a loop'],
  'same skill twice' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades'], ['id' => 'b', 'skill' => 'A', 'region' => 'blades']], 'more than one node'],
  'unknown region' => [[['id' => 'a', 'skill' => 'A', 'region' => 'storms']], 'unknown region'],
  'unknown requirement' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'requires' => ['z']]], 'unknown node z'],
  'negative cost' => [[['id' => 'a', 'skill' => 'A', 'region' => 'blades', 'cost' => -1]], 'less than 0 EXP'],
]);
