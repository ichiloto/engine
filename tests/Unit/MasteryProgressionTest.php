<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillDraft;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use Ichiloto\Engine\Progression\Mastery\MasteryTree;
use Ichiloto\Engine\Progression\Mastery\MasteryUnavailability;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Progression\Mastery\MasteryProgress;
use Ichiloto\Engine\Progression\Mastery\MasteryProgression;
use Ichiloto\Engine\Progression\Mastery\MasteryRefusal;
use Ichiloto\Engine\Progression\Mastery\MasteryRefusalReason;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Entities\Party;

/** A synthetic tree: two regions whose trunks hold the given skills as ungraded applications. */
function masteryTrunkTree(array $trunks, array $branches = []): MasteryTree
{
  return MasteryTree::fromArray(['regions' => array_map(static fn(string $region): array => ['id' => $region, 'name' => ucfirst($region),
    'nodes' => array_map(static fn(string $skill, string $id): array => ['id' => $id, 'kind' => 'application', 'skill' => $skill],
      $trunks[$region], array_keys($trunks[$region]))], array_keys($trunks)), 'branches' => $branches]);
}

/** A synthetic map: a free starting skill, two level-2 skills, and one that builds on the first of them and stays a secret. */
function masteryFixture(): array
{
  $skills = SkillCatalog::fromSkills([
    'Skills/start.php' => new SpecialSkill('Start Cut', '', '', 2, 0, effects: [new HPDamageSkillEffect('1')]),
    'Skills/spark.php' => new MagicSkill('Spark', '', '', 4, 0),
    'Skills/guard.php' => new SpecialSkill('Guard Up', '', '', 3, 0),
    'Skills/storm.php' => new MagicSkill('Storm', '', '', 9, 0),
  ]);
  $tree = masteryTrunkTree(['blades' => ['start' => 'Start Cut', 'guard' => 'Guard Up'], 'storms' => ['spark' => 'Spark', 'storm' => 'Storm']]);
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [
    ['id' => 'start', 'cost' => 0],
    ['id' => 'spark', 'cost' => 50, 'opensAtLevel' => 2],
    ['id' => 'guard', 'cost' => 40, 'opensAtLevel' => 2],
    ['id' => 'storm', 'cost' => 80, 'opensAtLevel' => 2, 'requires' => ['spark'], 'hidden' => true],
  ]], $tree);

  return [new MasteryProgression($map, $skills), new Character('Hero', 0, new Stats())];
}

/**
 * A synthetic element branch on the tree's own terms: a foundation that
 * grants nothing yet, two grades, an advanced application whose record
 * awaits its author's values, and a demi and full special grade.
 */
function masteryBranchFixture(): array
{
  $draft = SkillDraft::fromArray(['kind' => 'magic', 'name' => 'Inferno', 'description' => '', 'icon' => '', 'cooldown' => 0,
    'occasion' => 'Battle Screen', 'scope' => ['side' => 'Enemy', 'number' => 'All', 'status' => 'Alive'],
    'invocation' => ['speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'effects' => [['type' => 'hp_damage', 'element' => 'Flame']], 'unassigned' => ['cost', 'effects.0.formula']]);
  $skills = SkillCatalog::fromSkills([
    'Skills/a.php' => new MagicSkill('Singe I', '', '', 3, 0),
    'Skills/b.php' => new MagicSkill('Singe II', '', '', 6, 0),
    'Skills/c.php' => new MagicSkill('Half Shield', '', '', 4, 0),
    'Skills/d.php' => new MagicSkill('Shield', '', '', 8, 0),
  ], drafts: ['Skills/e.php' => $draft]);
  $tree = MasteryTree::fromArray(['regions' => [['id' => 'arts', 'name' => 'Arts']], 'branches' => [
    ['id' => 'flame', 'region' => 'arts', 'name' => 'Flame', 'kind' => 'element', 'element' => 'Flame', 'nodes' => [
      ['id' => 'flame', 'kind' => 'foundation', 'name' => 'Flame', 'cost' => 10],
      ['id' => 'singe-i', 'kind' => 'application', 'grade' => 1, 'skill' => 'Singe I', 'cost' => 20],
      ['id' => 'singe-ii', 'kind' => 'application', 'grade' => 2, 'skill' => 'Singe II'],
      ['id' => 'inferno', 'kind' => 'advanced', 'skill' => 'Inferno', 'cost' => 90],
      ['id' => 'half-shield', 'kind' => 'special', 'phase' => 'demi', 'skill' => 'Half Shield', 'cost' => 30],
      ['id' => 'shield', 'kind' => 'special', 'phase' => 'full', 'extends' => 'half-shield', 'skill' => 'Shield', 'cost' => 60],
    ]],
  ]]);

  return [$tree, $skills];
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
    ->and(masteryStates($mastery, $hero))->toBe(['start' => 'learned', 'guard' => 'locked', 'spark' => 'locked', 'storm' => 'locked']);
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
    ->and(masteryStates($mastery, $hero))->toBe(['start' => 'learned', 'guard' => 'learned', 'spark' => 'learned', 'storm' => 'open']);
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
    ->and($mastery->describe($hero)[2]->affordable)->toBeFalse()
    ->and($mastery->describe($hero)[1]->learnable)->toBeTrue();
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

it('refuses a map whose terms repeat, name a node the tree lacks, require an unknown node, or loop', function (array $nodes, string $message) {
  $tree = masteryTrunkTree(['blades' => ['a' => 'A', 'b' => 'B']]);

  expect(fn() => MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => $nodes], $tree))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'loop' => [[['id' => 'a', 'requires' => ['b']], ['id' => 'b', 'requires' => ['a']]], 'in a loop'],
  'same node twice' => [[['id' => 'a'], ['id' => 'a']], 'node a twice'],
  'node outside the tree' => [[['id' => 'z']], 'does not have'],
  'unknown requirement' => [[['id' => 'a', 'requires' => ['z']]], 'unknown node z'],
  'negative cost' => [[['id' => 'a', 'cost' => -1]], 'less than 0 points'],
  'skill named by the map, from before the tree owned it' => [[['id' => 'a', 'skill' => 'A']], 'ichiloto upgrade'],
  'study order from before Mastery spent points' => [[['id' => 'a', 'order' => 1]], 'unknown key "order"'],
]);

it('refuses a tree that teaches a skill twice, repeats a node, grows a branch from an unknown region, or breaks its ladder', function (array $data, string $message) {
  expect(fn() => MasteryTree::fromArray($data))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'skill twice' => [['regions' => [['id' => 'r', 'nodes' => [['id' => 'a', 'kind' => 'application', 'skill' => 'A'],
    ['id' => 'b', 'kind' => 'application', 'skill' => 'A']]]]], 'teaches A on both'],
  'node twice' => [['regions' => [['id' => 'r', 'nodes' => [['id' => 'a', 'kind' => 'application', 'skill' => 'A'],
    ['id' => 'a', 'kind' => 'application', 'skill' => 'B']]]]], 'node a twice'],
  'unknown region' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 's', 'kind' => 'aspect']]], 'unknown region'],
  'graded node on a trunk' => [['regions' => [['id' => 'r', 'nodes' => [['id' => 'a', 'kind' => 'application', 'grade' => 1, 'skill' => 'A']]]]], 'trunk'],
  'grade gap' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'nodes' => [
    ['id' => 'a', 'kind' => 'application', 'grade' => 1, 'skill' => 'A'], ['id' => 'c', 'kind' => 'application', 'grade' => 3, 'skill' => 'C']]]]], 'without a gap'],
  'foundation not first' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'nodes' => [
    ['id' => 'a', 'kind' => 'application', 'skill' => 'A'], ['id' => 'f', 'kind' => 'foundation', 'name' => 'F']]]]], 'must come first'],
  'full phase without its demi phase' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'nodes' => [
    ['id' => 'a', 'kind' => 'special', 'phase' => 'full', 'skill' => 'A']]]]], 'extends its demi phase'],
  'element branch without its element' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'element']]], 'names its element'],
  'unnamed foundation' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'nodes' => [
    ['id' => 'f', 'kind' => 'foundation']]]]], 'needs a name'],
  'branch within an unknown branch' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'within' => 'y']]],
    'grows within unknown branch "y"'],
  'branch within itself' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'within' => 'x']]],
    'must grow within another branch'],
  'branches within each other' => [['regions' => [['id' => 'r']], 'branches' => [['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'within' => 'y'],
    ['id' => 'y', 'region' => 'r', 'kind' => 'aspect', 'within' => 'x']]], 'in a loop'],
  'branch within another region\'s branch' => [['regions' => [['id' => 'r'], ['id' => 's']], 'branches' => [
    ['id' => 'x', 'region' => 'r', 'kind' => 'aspect', 'within' => 'y'], ['id' => 'y', 'region' => 's', 'kind' => 'aspect']]], 'in another region'],
]);

it('continues the chain of the branch a branch grows within, and leaves the chain alone where that branch has no foundation', function () {
  $tree = MasteryTree::fromArray(['regions' => [['id' => 'arts', 'name' => 'Arts']], 'branches' => [
    ['id' => 'flame', 'region' => 'arts', 'name' => 'Flame', 'kind' => 'element', 'element' => 'Flame', 'nodes' => [
      ['id' => 'flame', 'kind' => 'foundation', 'name' => 'Flame'], ['id' => 'singe', 'kind' => 'application', 'grade' => 1, 'skill' => 'Singe']]],
    // Within a branch with a foundation: its own foundation, and the steps of a sub-branch without one, grow from it.
    ['id' => 'ember', 'region' => 'arts', 'name' => 'Ember', 'kind' => 'aspect', 'within' => 'flame', 'nodes' => [
      ['id' => 'ember', 'kind' => 'foundation', 'name' => 'Ember'], ['id' => 'glow', 'kind' => 'application', 'skill' => 'Glow']]],
    ['id' => 'ash', 'region' => 'arts', 'name' => 'Ash', 'kind' => 'aspect', 'within' => 'ember', 'nodes' => [
      ['id' => 'soot', 'kind' => 'application', 'skill' => 'Soot'], ['id' => 'cinder', 'kind' => 'advanced', 'skill' => 'Cinder']]],
    // Within a branch without a foundation, nothing changes: its steps build on nothing, as they would on their own.
    ['id' => 'odd', 'region' => 'arts', 'name' => 'Odd', 'kind' => 'non-elemental', 'nodes' => [['id' => 'quirk', 'kind' => 'application', 'skill' => 'Quirk']]],
    ['id' => 'hours', 'region' => 'arts', 'name' => 'Hours', 'kind' => 'non-elemental', 'within' => 'odd', 'nodes' => [
      ['id' => 'hasten', 'kind' => 'application', 'skill' => 'Hasten'], ['id' => 'skip', 'kind' => 'advanced', 'skill' => 'Skip']]],
  ]]);

  expect(array_map(static fn($node): array => $tree->getRequirements($node), $tree->nodes))->toBe(['flame' => [], 'singe' => ['flame'],
    'ember' => ['flame'], 'glow' => ['ember'], 'soot' => ['ember'], 'cinder' => ['ember'], 'quirk' => [], 'hasten' => [], 'skip' => []])
    ->and($tree->branches['hours']->within)->toBe('odd')
    ->and($tree->findOuterBranch($tree->branches['ash']))->toBe($tree->branches['ember']);
});

it('chains a branch from its foundation up its grades to its advanced applications, and a full phase from its demi phase', function () {
  [$tree] = masteryBranchFixture();
  $requirements = array_map(static fn($node): array => $tree->getRequirements($node), $tree->nodes);

  expect($requirements)->toBe(['flame' => [], 'singe-i' => ['flame'], 'singe-ii' => ['singe-i'], 'inferno' => ['singe-ii'],
    'half-shield' => ['flame'], 'shield' => ['half-shield']]);
});

it('lets every character reach the whole tree on its terms, and keeps unfinished or unpriced nodes unavailable without taking points', function () {
  [$tree, $skills] = masteryBranchFixture();
  $catalog = new MasteryCatalog($tree);
  $stranger = new Character('Stranger', 0, new Stats());
  $mastery = new MasteryProgression($catalog->findMap('stranger'), $skills);
  $stranger->mastery->points = 200;
  $statuses = array_column(array_map(static fn($status): array => [$status->node->id, $status], $mastery->describe($stranger)), 1, 0);

  expect($statuses['flame']->state)->toBe(MasteryNodeState::OPEN)
    ->and($statuses['singe-i']->missing)->toBe(['flame'])
    ->and($statuses['singe-ii']->unavailability)->toBe(MasteryUnavailability::UNPRICED)
    ->and($statuses['inferno']->unavailability)->toBe(MasteryUnavailability::AWAITING_VALUES)
    ->and(array_keys(array_filter($statuses, static fn($status): bool => $status->offered)))->toBe(['flame', 'singe-i', 'half-shield', 'shield'])
    ->and(masteryRefusal($mastery, $stranger, 'inferno'))->toBe(MasteryRefusalReason::UNAVAILABLE)
    ->and($stranger->mastery->points)->toBe(200);

  // A foundation that grants no skill yet is learned for its place in the chain alone.
  $foundation = $mastery->learn($stranger, 'flame');
  $singe = $mastery->learn($stranger, 'singe-i');

  expect($foundation->learned)->toBe([])
    ->and($stranger->mastery->hasLearned('flame'))->toBeTrue()
    ->and(array_map(static fn($skill): string => $skill->name, $singe->learned))->toBe(['Singe I'])
    ->and($stranger->mastery->points)->toBe(170);
});

it('never learns an unavailable node for free, but counts a skill the character already knows as learned', function () {
  [$tree, $skills] = masteryBranchFixture();
  $hero = new Character('Hero', 0, new Stats());
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [['id' => 'singe-ii', 'cost' => 0], ['id' => 'inferno', 'cost' => 0]]], $tree);
  $mastery = new MasteryProgression($map, $skills);
  $hero->learnSkill(new MagicSkill('Half Shield', '', '', 4, 0));

  $advance = $mastery->reconcile($hero);

  expect(array_map(static fn($skill): string => $skill->name, $advance->learned))->toBe(['Singe II'])
    ->and($hero->mastery->hasLearned('inferno'))->toBeFalse()
    ->and($hero->mastery->hasLearned('half-shield'))->toBeTrue();
});

it('keeps a foundation unavailable while nothing built on it can be learned, so it never takes points for nothing', function () {
  $chill = static fn(bool $finished): SkillCatalog => $finished
    ? SkillCatalog::fromSkills(['Skills/chill.php' => new MagicSkill('Chill I', '', '', 5, 0)])
    : SkillCatalog::fromSkills([], drafts: ['Skills/chill.php' => SkillDraft::fromArray(['kind' => 'magic', 'name' => 'Chill I', 'description' => '',
      'icon' => '', 'cooldown' => 0, 'occasion' => 'Battle Screen', 'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
      'invocation' => ['speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0], 'cost' => 5,
      'effects' => [['type' => 'hp_damage', 'formula' => '1', 'element' => 'Frost']], 'unassigned' => ['effects.0.variance']])]);
  $tree = MasteryTree::fromArray(['regions' => [['id' => 'arts', 'name' => 'Arts']], 'branches' => [
    ['id' => 'frost', 'region' => 'arts', 'name' => 'Frost', 'kind' => 'element', 'element' => 'Frost', 'nodes' => [
      ['id' => 'frost', 'kind' => 'foundation', 'name' => 'Frost', 'cost' => 10, 'opensAtLevel' => 2],
      ['id' => 'chill-i', 'kind' => 'application', 'grade' => 1, 'skill' => 'Chill I', 'cost' => 20, 'opensAtLevel' => 3],
    ]],
  ]]);
  $stranger = new Character('Stranger', 0, new Stats());
  $stranger->mastery->points = 100;
  $waiting = new MasteryProgression(new MasteryCatalog($tree)->findMap('Stranger'), $chill(false));
  $foundation = static fn(MasteryProgression $mastery) => array_find($mastery->describe($stranger), static fn($status): bool => $status->node->id === 'frost');

  expect($foundation($waiting)->unavailability)->toBe(MasteryUnavailability::NOTHING_TO_OPEN)
    ->and($foundation($waiting)->offered)->toBeFalse()
    ->and(masteryRefusal($waiting, $stranger, 'frost'))->toBe(MasteryRefusalReason::UNAVAILABLE)
    ->and($stranger->mastery->points)->toBe(100)
    // Once the application it opens is finished, the foundation is offered on the tree's terms.
    ->and($foundation(new MasteryProgression(new MasteryCatalog($tree)->findMap('Stranger'), $chill(true)))->unavailability)->toBeNull();
});

it('counts a foundation as learned once the character knows a node built on it, without spending points', function () {
  [$tree, $skills] = masteryBranchFixture();
  // The character's own terms start partway along the branch: Singe I with no foundation.
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [['id' => 'singe-i', 'cost' => 0]]], $tree);
  $mastery = new MasteryProgression($map, $skills);
  $hero = new Character('Hero', 0, new Stats());
  $hero->mastery->points = 50;

  $mastery->reconcile($hero);

  expect($hero->mastery->hasLearned('singe-i'))->toBeTrue()
    ->and($hero->mastery->hasLearned('flame'))->toBeTrue()
    ->and($hero->mastery->points)->toBe(50);
});

it('gives each node the tree\'s price and opening level unless the character\'s own terms state theirs', function () {
  [$tree, $skills] = masteryBranchFixture();
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [['id' => 'singe-i', 'cost' => 0, 'opensAtLevel' => 1], ['id' => 'half-shield', 'cost' => 45, 'opensAtLevel' => 6]]], $tree);
  $stranger = new MasteryCatalog($tree)->findMap('Stranger');
  $terms = static fn(MasteryMap $map, string $id): array => [$map->findNode($id)->cost, $map->findNode($id)->opensAtLevel];

  expect($terms($map, 'singe-i'))->toBe([0, 1])
    ->and($terms($map, 'half-shield'))->toBe([45, 6])
    ->and($terms($map, 'shield'))->toBe([60, 1])
    ->and($terms($stranger, 'singe-i'))->toBe([20, 1])
    ->and($terms($stranger, 'half-shield'))->toBe([30, 1]);
});

it('gives a character\'s own terms in full, and reports the chain step those terms leave out', function () {
  [$tree, $skills] = masteryBranchFixture();
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [['id' => 'singe-i', 'cost' => 0], ['id' => 'singe-ii', 'cost' => 15, 'requires' => ['singe-i']]]], $tree);

  expect($map->findNode('singe-i')->requires)->toBe([])
    ->and($map->findNode('singe-i')->mapped)->toBeTrue()
    ->and($map->findNode('flame')->mapped)->toBeFalse()
    ->and($map->findUnchainedTerms())->toBe(['singe-i' => 'flame']);
});

it('reads a draft skill record without guessing what awaits its author, and refuses one that still gives a listed value', function () {
  $data = ['kind' => 'magic', 'name' => 'Inferno', 'description' => '', 'icon' => '', 'cooldown' => 0, 'occasion' => 'Battle Screen',
    'scope' => ['side' => 'Enemy', 'number' => 'All', 'status' => 'Alive'], 'invocation' => ['speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'effects' => [['type' => 'hp_damage', 'element' => 'Flame']], 'unassigned' => ['cost', 'effects.0.formula']];
  $draft = SkillDraft::fromArray($data);
  $catalog = SkillCatalog::fromSkills([], drafts: ['Skills/inferno.php' => $draft]);

  expect($draft->unassigned)->toBe(['cost', 'effects.0.formula'])
    ->and($catalog->findSkill('Inferno'))->toBeNull()
    ->and($catalog->findDraft('Inferno'))->toBe($draft)
    ->and(fn() => SkillRecord::readSkill($data))->toThrow(InvalidArgumentException::class, 'draft')
    ->and(fn() => SkillDraft::fromArray([...$data, 'cost' => 9]))->toThrow(InvalidArgumentException::class, 'leave it out')
    ->and(fn() => SkillDraft::fromArray([...$data, 'unassigned' => ['icon']]))->toThrow(InvalidArgumentException::class, 'cannot await')
    // Only the spread awaiting its author: the record stays a draft rather than taking the Engine's default spread.
    ->and(SkillDraft::fromArray([...$data, 'cost' => 9, 'effects' => [['type' => 'hp_damage', 'formula' => '1', 'element' => 'Flame']],
      'unassigned' => ['effects.0.variance']])->unassigned)->toBe(['effects.0.variance'])
    ->and(fn() => SkillDraft::fromArray([...$data, 'cost' => 9, 'effects' => [['type' => 'hp_damage', 'formula' => '1', 'variance' => 0.1]],
      'unassigned' => ['effects.0.variance']]))->toThrow(InvalidArgumentException::class, 'leave it out');
});

it('shows a draft\'s values its author has given, and nothing for a value still awaiting the author', function () {
  $data = ['kind' => 'magic', 'name' => 'Inferno', 'description' => 'Flames everywhere.', 'icon' => '', 'cooldown' => 0, 'occasion' => 'Battle Screen',
    'scope' => ['side' => 'Enemy', 'number' => 'All', 'status' => 'Alive'], 'invocation' => ['speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'cost' => 32, 'effects' => [['type' => 'hp_damage', 'formula' => '1', 'element' => 'Flame']], 'unassigned' => ['effects.0.variance']];
  $given = SkillDraft::fromArray($data);
  $awaiting = SkillDraft::fromArray([...array_diff_key($data, ['cost' => 1, 'scope' => 1]), 'unassigned' => ['cost', 'scope']]);

  expect($given->getCost())->toBe(32)
    ->and(\Ichiloto\Engine\Localization\TargetWords::describeScope($given->getScope()))->toBe('All foes')
    ->and($given->getDescription())->toBe('Flames everywhere.')
    // A stand-in that only checks the rest of the record is never shown.
    ->and($awaiting->getCost())->toBeNull()
    ->and($awaiting->getScope())->toBeNull();
});

it('reports a role curriculum that names a node the tree does not have', function () {
  $catalog = new \Ichiloto\Engine\Progression\Mastery\MasteryCatalog(masteryTrunkTree(['blades' => ['a' => 'A']]));

  expect($catalog->findCurriculumProblems([['name' => 'Scholar', 'curriculum' => ['a', 'z']], ['name' => 'Squire']]))
    ->toBe(["classes.php: Scholar's curriculum names node z, which the mastery tree does not have"]);
});

it('places unplaced nodes down the middle of their region in the tree\'s order, and authored ones where the author put them', function () {
  $tree = masteryTrunkTree(['blades' => ['a' => 'A', 'b' => 'B'], 'storms' => ['c' => 'C'], 'wards' => [], 'guards' => []]);
  $map = MasteryMap::fromArray(['actor' => 'Hero', 'nodes' => [['id' => 'c', 'position' => ['x' => 0.9, 'y' => 0.1]]]], $tree);
  $places = \Ichiloto\Engine\Progression\Mastery\MasteryLayout::place($map, ['blades', 'storms', 'wards', 'guards']);

  expect($places['a'])->toBe(['x' => 0.25, 'y' => 1 / 6, 'placed' => false])
    ->and($places['b'])->toBe(['x' => 0.25, 'y' => 2 / 6, 'placed' => false])
    ->and($places['c'])->toBe(['x' => 0.9, 'y' => 0.1, 'placed' => true]);
});
