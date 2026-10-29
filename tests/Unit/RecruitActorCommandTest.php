<?php

use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\RecruitmentLevelPolicy;
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
