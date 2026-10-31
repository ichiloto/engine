<?php

use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\RecruitmentLevelPolicy;
use Ichiloto\Engine\Entities\RecruitmentPointPolicy;
use Ichiloto\Engine\Entities\RecruitmentPointCatchUp;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryProgress;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\Interpreter\Commands\RecruitActorCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;

beforeEach(function () {
  $this->previousActors = ConfigStore::has(ActorStore::class) ? ConfigStore::get(ActorStore::class) : null;
  $this->actors = new ActorStore(__DIR__ . '/../Fixtures/Actors');
  ConfigStore::put(ActorStore::class, $this->actors);
  $this->party = new Party();
  $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, $this->party);
});
afterEach(function () {
  $this->previousActors === null ? ConfigStore::remove(ActorStore::class) : ConfigStore::put(ActorStore::class, $this->previousActors);
});

it('recruits from the actor factory once and preserves acquired progress on repeat and saved restoration', function () {
  $command = new RecruitActorCommand();
  $context = new ScriptCommandContext($this->scene);
  expect($command->execute($context, ['actor' => 'actor.hero'])->operation)->toBeNull();
  $hero = $this->party->leader;
  expect($hero->actorId)->toBe('actor.hero')->and($this->party->members->count())->toBe(1);
  $hero->stats->currentHp = 23;
  $saved = $hero->toArray();
  $command->execute($context, ['actor' => 'actor.hero']);
  expect($this->party->leader)->toBe($hero)->and($hero->toArray())->toBe($saved)->and($this->party->members->count())->toBe(1);
  $loaded = unserialize(serialize($this->party));
  expect($loaded->recruitActor($this->actors->require('actor.hero', 'repeat after load')))->toBeFalse()
    ->and($loaded->leader->stats->currentHp)->toBe(23)->and($loaded->members->count())->toBe(1);
});

it('appends a new recruit to reserves while keeping the established frontline and leader', function () {
  foreach (range(1, Party::BATTLE_SIZE) as $index) { $this->party->addMember(new Character('Existing ' . $index, 0, new Stats())); }
  $frontline = $this->party->battlers->toArray();
  expect($this->party->recruitActor($this->actors->require('actor.hero', 'recruiting reserve')))->toBeTrue()
    ->and($this->party->battlers->toArray())->toBe($frontline)
    ->and($this->party->members->toArray()[Party::BATTLE_SIZE]->actorId)->toBe('actor.hero');
});

it('refuses invalid references and a missing party before changing the roster', function () {
  $command = new RecruitActorCommand();
  $context = new ScriptCommandContext($this->scene);
  expect(fn() => $command->execute($context, ['actor' => 'unknown']))->toThrow(UnresolvedSaveReferenceException::class)
    ->and(fn() => $command->execute($context, ['actor' => []]))->toThrow(RuntimeException::class)
    ->and($this->party->members->count())->toBe(0);
  $battle = $this->createStub(\Ichiloto\Engine\Battle\Events\BattleEventControlInterface::class);
  expect(fn() => $command->execute(new ScriptCommandContext($this->scene, battle: $battle), ['actor' => 'actor.hero']))
    ->toThrow(RuntimeException::class, 'only in field scripts');
  new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, null);
  expect(fn() => $command->execute($context, ['actor' => 'actor.hero']))->toThrow(RuntimeException::class, 'configured party');
});

it('declares a required actor picker for field scripts and excludes battle-page roster mutation', function () {
  $catalog = ScriptCommandCatalog::createEngineCatalog();
  $definition = $catalog->findDefinition('recruit_actor');
  expect($definition->fields[0]->reference)->toBe(ScriptCommandReference::ACTOR)
    ->and($definition->fields[0]->required)->toBeTrue()
    ->and($definition->runsIn(EventExecutionScope::FIELD))->toBeTrue()
    ->and($definition->runsIn(EventExecutionScope::BATTLE))->toBeFalse()
    ->and($catalog->createHandler('recruit_actor'))->toBeInstanceOf(RecruitActorCommand::class);
});


it('keeps actors with equal display names distinct by their authored identity', function () {
  $first = $this->actors->require('actor.hero', 'recruiting first identity');
  $other = ActorDefinition::fromArray([...$first->data(), 'id' => 'actor.other']);
  expect($this->party->recruitActor($first))->toBeTrue()->and($this->party->recruitActor($other))->toBeTrue()
    ->and(array_column($this->party->members->toArray(), 'actorId'))->toBe(['actor.hero', 'actor.other']);
});

it('matches a new recruit to the roster average before joining and preserves existing progress', function () {
  foreach ([3, 5, 7, 12] as $index => $level) {
    $member = new Character('Member ' . $index, 0, new Stats());
    $member->addExperience($member->getLevelExperienceThresholds()[$level]);
    $member->stats->currentHp = 1;
    $this->party->addMember($member);
  }
  $before = array_map(static fn(Character $member) => $member->toArray(), $this->party->members->toArray());
  $frontline = $this->party->battlers->toArray();
  (new RecruitActorCommand())->execute(new ScriptCommandContext($this->scene), [
    'actor' => 'actor.hero', 'levelPolicy' => 'partyAverage',
  ]);
  $members = $this->party->members->toArray();
  $recruit = $members[4];
  expect($recruit->level)->toBe(6)
    ->and($recruit->currentExp)->toBe($recruit->getLevelExperienceThresholds()[6])
    ->and($this->party->battlers->toArray())->toBe($frontline)
    ->and(array_map(static fn(Character $member) => $member->toArray(), array_slice($members, 0, 4)))->toBe($before);

  $recruit->stats->currentHp = 9;
  $loaded = unserialize(serialize($this->party));
  $saved = $loaded->members->toArray()[4]->toArray();
  expect($loaded->recruitActor($this->actors->require('actor.hero', 'repeat recruitment'), RecruitmentLevelPolicy::PARTY_AVERAGE))->toBeFalse()
    ->and($loaded->members->toArray()[4]->toArray())->toBe($saved);
});

it('refuses an empty average and invalid policies without partially recruiting', function () {
  $definition = $this->actors->require('actor.hero', 'testing recruitment policy');
  expect(fn() => $this->party->recruitActor($definition, RecruitmentLevelPolicy::PARTY_AVERAGE))
    ->toThrow(InvalidArgumentException::class, 'existing party');
  foreach (['average', '', 3, []] as $policy) {
    expect(fn() => (new RecruitActorCommand())->execute(new ScriptCommandContext($this->scene), [
      'actor' => 'actor.hero', 'levelPolicy' => $policy,
    ]))->toThrow(RuntimeException::class, 'levelPolicy');
  }
  expect($this->party->members->count())->toBe(0);
});

it('declares an optional constrained starting-level policy in the shared command schema', function () {
  $definition = ScriptCommandCatalog::createEngineCatalog()->findDefinition('recruit_actor');
  $field = $definition->fields[1];
  expect($field->key)->toBe('levelPolicy')->and($field->required)->toBeFalse()
    ->and($field->options)->toBe(['authored', 'partyAverage']);
});

it('starts authored free Mastery knowledge with known history and preserves unknown historical save spending', function () {
  $source = $this->actors->require('actor.hero', 'testing fresh versus restored SP history');
  $fresh = $source->createCharacter();
  $fresh->mastery->addPoints(25);
  $fresh->mastery->markLearned('free-node');
  expect($fresh->mastery->points)->toBe(25)->and($fresh->mastery->spentPoints)->toBe(0)
    ->and($fresh->mastery->earnedPoints)->toBe(25);
  $restored = $source->createCharacter(['mastery' => ['points' => 15, 'learned' => ['legacy-node']]]);
  expect($restored->mastery->points)->toBe(15)->and($restored->mastery->spentPoints)->toBeNull()
    ->and($restored->mastery->earnedPoints)->toBeNull();
});

it('grants the roster average earned SP once, including reserves and members away during a split', function () {
  foreach ([100, 160, 210, 250] as $i => $total) {
    $member = new Character('Member ' . $i, 0, new Stats());
    $member->mastery->addPoints($total);
    $member->mastery->spendPoints(40);
    $member->stats->currentHp = 1;
    $this->party->addMember($member);
  }
  $this->party->selectParticipants([$this->party->members->toArray()[0]->actorId]);
  $before = array_map(fn(Character $member) => $member->toArray(), $this->party->members->toArray());
  (new RecruitActorCommand())->execute(new ScriptCommandContext($this->scene), [
    'actor' => 'actor.hero', 'levelPolicy' => 'partyAverage', 'spPolicy' => 'partyAverageEarned',
  ]);
  $new = $this->party->members->toArray()[4];
  expect($new->mastery->points)->toBe(180)->and($new->mastery->earnedPoints)->toBe(180)
    ->and($this->party->participants->count())->toBe(1)
    ->and(array_map(fn(Character $member) => $member->toArray(), array_slice($this->party->members->toArray(), 0, 4)))->toBe($before);
  $new->mastery->spendPoints(50);
  $loaded = unserialize(serialize($this->party));
  $saved = $loaded->members->toArray()[4]->toArray();
  expect($loaded->recruitActor($this->actors->require('actor.hero', 'repeat point recruitment'),
    spPolicy: RecruitmentPointPolicy::PARTY_AVERAGE_EARNED))->toBeFalse()
    ->and($loaded->members->toArray()[4]->toArray())->toBe($saved);
});

it('initializes explicitly estimated legacy SP once and preserves it across repricing and future recruits', function () {
  $directory = getcwd();
  $root = createTestDirectory('recruit-sp-');
  mkdir($root . '/assets/Data', 0700, true);
  $write = static function (int $price) use ($root): void {
    file_put_contents($root . '/assets/Data/mastery.php', '<?php return ' . var_export([
      'regions' => [['id' => 'arts', 'name' => 'Arts']],
      'branches' => [['id' => 'path', 'region' => 'arts', 'name' => 'Path', 'kind' => 'element', 'element' => 'Flame',
        'nodes' => [['id' => 'learned', 'kind' => 'foundation', 'name' => 'Learned', 'cost' => $price]]]],
    ], true) . ';');
  };
  $member = new Character('Legacy member', 0, new Stats());
  $member->mastery = MasteryProgress::fromArray(['points' => 70, 'learned' => ['learned']]);
  $member->mastery->spendPoints(10);
  $member->mastery->markLearned('newly-paid');
  $this->party->addMember($member);
  $before = $member->mastery->jsonSerialize();
  $write(30);
  chdir($root);
  try {
    $definition = $this->actors->require('actor.hero', 'legacy SP recruitment');
    expect(fn() => $this->party->recruitActor($definition, spPolicy: RecruitmentPointPolicy::PARTY_AVERAGE_EARNED))
      ->toThrow(InvalidArgumentException::class)
      ->and($this->party->members->count())->toBe(1)->and($member->mastery->jsonSerialize())->toBe($before);
    $unpriced = new Character('Missing historical node', 0, new Stats());
    $unpriced->mastery = MasteryProgress::fromArray(['points' => 20, 'learned' => ['missing-node']]);
    expect(fn() => RecruitmentPointCatchUp::prepare([$member, $unpriced], RecruitmentPointPolicy::PARTY_AVERAGE_ESTIMATED_EARNED))
      ->toThrow(InvalidArgumentException::class, 'current SP price')
      ->and($member->mastery->spentPoints)->toBeNull()->and($unpriced->mastery->spentPoints)->toBeNull();
    $bad = ActorDefinition::fromArray([...$definition->data(), 'id' => 'actor.bad', 'currentExp' => -1]);
    expect(fn() => $this->party->recruitActor($bad, spPolicy: RecruitmentPointPolicy::PARTY_AVERAGE_ESTIMATED_EARNED))
      ->toThrow(InvalidArgumentException::class)->and($member->mastery->jsonSerialize())->toBe($before)
      ->and($this->party->members->count())->toBe(1);
    $this->party->recruitActor($definition, spPolicy: RecruitmentPointPolicy::PARTY_AVERAGE_ESTIMATED_EARNED);
    expect($member->mastery->points)->toBe(60)->and($member->mastery->spentPoints)->toBe(40)
      ->and($member->mastery->earnedPoints)->toBe(100)
      ->and($this->party->members->toArray()[1]->mastery->points)->toBe(100);
    $write(900);
    MasteryCatalog::forgetProjectCatalogs();
    $other = ActorDefinition::fromArray([...$definition->data(), 'id' => 'actor.other']);
    $this->party->recruitActor($other, spPolicy: RecruitmentPointPolicy::PARTY_AVERAGE_ESTIMATED_EARNED);
    expect($member->mastery->spentPoints)->toBe(40)
      ->and($this->party->members->toArray()[2]->mastery->points)->toBe(100);
  } finally {
    chdir($directory);
    MasteryCatalog::forgetProjectCatalogs();
  }
});

it('rejects empty averages and invalid point policies without partially recruiting', function () {
  $definition = $this->actors->require('actor.hero', 'invalid SP recruitment');
  expect(fn() => $this->party->recruitActor($definition, spPolicy: RecruitmentPointPolicy::PARTY_AVERAGE_EARNED))
    ->toThrow(InvalidArgumentException::class);
  foreach (['average', [], 4, ''] as $policy) {
    expect(fn() => (new RecruitActorCommand())->execute(new ScriptCommandContext($this->scene),
      ['actor' => 'actor.hero', 'spPolicy' => $policy]))->toThrow(RuntimeException::class, 'spPolicy');
  }
  expect($this->party->members->count())->toBe(0)
    ->and(ScriptCommandCatalog::createEngineCatalog()->findDefinition('recruit_actor')->fields[2]->options)
    ->toBe(['authored', 'partyAverageEarned', 'partyAverageEstimatedEarned']);
});
