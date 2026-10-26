<?php

use Assegai\Util\Path;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonWielderPolicy;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Exceptions\SummonAssignmentException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ClassStore;

beforeEach(function () {
  $this->identityStatics = [];
  foreach ([BattleCommandCatalog::class, SkillCatalog::class, ConfigStore::class, ClassStore::class] as $class) {
    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
      $this->identityStatics[] = [$property, $property->getValue()];
    }
  }
  ConfigStore::put(ProjectConfig::class, new PlaySettings([]));
  (new ReflectionProperty(ClassStore::class, 'definitions'))->setValue(null, ['hero' => ['name' => 'Hero']]);
});

afterEach(function () {
  foreach ($this->identityStatics as [$property, $value]) {
    $property->setValue(null, $value);
  }
});

function createWielderIdentityDefinition(string $id, string $name): ActorDefinition
{
  return ActorDefinition::fromArray([
    'id' => $id,
    'name' => $name,
    'currentExp' => 0,
    'stats' => (new Stats())->jsonSerialize(),
  ]);
}

function createWielderIdentitySummon(array $actorIds, string $tenancy = 'shared'): SummonCutsceneDefinition
{
  return SummonCutsceneDefinition::fromArrays([
    'id' => 'identity-summon',
    'name' => 'Synthetic Call',
    'linkedActionId' => 'Synthetic Call',
    'wielders' => ['mode' => 'characters', 'characters' => $actorIds, 'tenancy' => $tenancy],
  ], ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []]);
}

function installWielderIdentityCatalog(SummonCutsceneDefinition $definition): void
{
  // Seed the real catalogue's battle cache without compiling art or reading a project.
  $library = new SummonCutsceneLibrary(cacheForBattle: true);
  (new ReflectionProperty(SummonCutsceneLibrary::class, 'battleDefinitions'))->setValue($library, [$definition]);
  (new ReflectionProperty(BattleCommandCatalog::class, 'battleSummons'))->setValue(null, $library);
  $catalog = SkillCatalog::fromSkills([
    'synthetic-call.php' => new SpecialSkill('Synthetic Call', 'Synthetic identity action', '', 2, 0),
  ], summons: [$definition]);
  (new ReflectionProperty(SkillCatalog::class, 'projectCatalogs'))->setValue(null, [
    Path::join(Path::getCurrentWorkingDirectory(), 'assets') => $catalog,
  ]);
}

function getWielderIdentityCommands(Character $character, Party $party): array
{
  return array_map(
    static fn($action) => BattleCommandType::fromCommandName($action->name),
    BattleCommandCatalog::buildCommands($character, $party),
  );
}

it('matches actor identity through renames without granting duplicate names or id-shaped names', function () {
  $eligible = createWielderIdentityDefinition('actor.allowed', 'Shared Name')->createCharacter();
  $excluded = createWielderIdentityDefinition('actor.excluded', 'Shared Name')->createCharacter();
  $policy = SummonWielderPolicy::fromAuthored(['mode' => 'characters', 'characters' => [' actor.allowed ']]);

  expect($policy->allowsCharacter($eligible))->toBeTrue()
    ->and($policy->allowsCharacter($excluded))->toBeFalse();
  $eligible->applySaveIdentity('Renamed Wielder');
  $excluded->applySaveIdentity('actor.allowed');
  expect($eligible->actorId)->toBe('actor.allowed')
    ->and($policy->allowsCharacter($eligible))->toBeTrue()
    ->and($policy->allowsCharacter($excluded))->toBeFalse();
});

it('round trips trimmed case-insensitive ids in the existing characters field', function () {
  $policy = SummonWielderPolicy::fromAuthored([
    'mode' => 'characters', 'characters' => ['  ACTOR.ALLOWED  '], 'tenancy' => 'exclusive',
  ]);
  $restored = SummonWielderPolicy::fromAuthored($policy->toArray());
  $character = createWielderIdentityDefinition('actor.allowed', 'Unrelated Display')->createCharacter();

  expect($restored->isValid())->toBeTrue()
    ->and($restored->characters)->toBe(['ACTOR.ALLOWED'])
    ->and($restored->isExclusive())->toBeTrue()
    ->and($restored->allowsCharacter($character))->toBeTrue()
    ->and($restored->toArray())->toBe($policy->toArray());
});

it('retains legacy original-name ids across runtime rename and explicit authored identity repair', function () {
  $legacy = new Character('Legacy Wielder', 0, new Stats());
  $policy = SummonWielderPolicy::fromAuthored(['mode' => 'characters', 'characters' => ['Legacy Wielder']]);
  $legacy->applySaveIdentity('Runtime Rename');
  $other = new Character('Other Original', 0, new Stats());
  $other->applySaveIdentity('Legacy Wielder');
  $legacy->assignSummon('identity-summon');
  $saved = $legacy->toArray();
  $repaired = createWielderIdentityDefinition('Legacy Wielder', 'Authored Rename')->createCharacter($saved);

  expect($legacy->actorId)->toBe('Legacy Wielder')
    ->and($policy->allowsCharacter($legacy))->toBeTrue()
    ->and($policy->allowsCharacter($other))->toBeFalse()
    ->and($repaired->name)->toBe('Authored Rename')
    ->and($policy->allowsCharacter($repaired))->toBeTrue();
  $party = new Party();
  $party->addMember($repaired);
  $party->assertSummonAssignments([createWielderIdentitySummon(['Legacy Wielder'])]);
  expect($repaired->hasSummon('identity-summon'))->toBeTrue();
});

it('requires explicit migration of stale name entries for actors with different ids', function () {
  $actor = createWielderIdentityDefinition('actor.allowed', 'Old Display')->createCharacter();
  $stale = SummonWielderPolicy::fromAuthored(['mode' => 'characters', 'characters' => ['Old Display']]);
  $migrated = SummonWielderPolicy::fromAuthored(['mode' => 'characters', 'characters' => ['actor.allowed']]);

  expect($stale->allowsCharacter($actor))->toBeFalse()
    ->and($migrated->allowsCharacter($actor))->toBeTrue();
  $actor->applySaveIdentity('New Display');
  expect($stale->allowsCharacter($actor))->toBeFalse()
    ->and($migrated->allowsCharacter($actor))->toBeTrue();
});

it('shares stable eligibility across grants command visibility execution rechecks and exclusions', function () {
  $definition = createWielderIdentitySummon(['actor.allowed']);
  installWielderIdentityCatalog($definition);
  $eligible = createWielderIdentityDefinition('actor.allowed', 'Shared Display')->createCharacter();
  $excluded = createWielderIdentityDefinition('actor.excluded', 'Shared Display')->createCharacter();
  $party = new Party();
  $party->addMember($eligible);
  $party->addMember($excluded);
  expect($party->assignSummon($definition, $eligible))->toBeTrue()
    ->and($party->assignSummon($definition, $excluded))->toBeFalse();
  $eligible->applySaveIdentity('Renamed Eligible');
  $excluded->applySaveIdentity('actor.allowed');
  expect($party->canAssignSummon($definition, $eligible))->toBeTrue()
    ->and($party->canAssignSummon($definition, $excluded))->toBeFalse()
    ->and(array_map(static fn($option) => $option->action->name,
      BattleCommandCatalog::buildOptions($eligible, $party, 'Summon')))->toBe(['Synthetic Call'])
    ->and(getWielderIdentityCommands($eligible, $party))->toContain(BattleCommandType::SUMMON)
    ->and(BattleCommandCatalog::canUseSummonAction($eligible, $party, 'Synthetic Call'))->toBeTrue();

  // Invalid restored/direct ownership cannot override the same command eligibility check.
  $excluded->assignSummon($definition->id);
  expect(BattleCommandCatalog::buildOptions($excluded, $party, 'Summon'))->toBe([])
    ->and(getWielderIdentityCommands($excluded, $party))->not->toContain(BattleCommandType::SUMMON)
    ->and(BattleCommandCatalog::canUseSummonAction($excluded, $party, 'Synthetic Call'))->toBeFalse()
    ->and(fn() => $party->assertSummonAssignments([$definition]))
    ->toThrow(SummonAssignmentException::class, 'not eligible');
});

it('does not remove an exclusive holder when a renamed excluded actor attempts reassignment', function () {
  $definition = createWielderIdentitySummon(['actor.allowed'], 'exclusive');
  installWielderIdentityCatalog($definition);
  $eligible = createWielderIdentityDefinition('actor.allowed', 'Original')->createCharacter();
  $excluded = createWielderIdentityDefinition('actor.excluded', 'Original')->createCharacter();
  $party = new Party();
  $party->addMember($eligible);
  $party->addMember($excluded);
  expect($party->assignSummon($definition, $eligible))->toBeTrue();
  $eligible->applySaveIdentity('Renamed');
  $excluded->applySaveIdentity('actor.allowed');
  expect($party->reassignSummon($definition, $excluded))->toBeFalse()
    ->and($party->getSummonHolders($definition->id))->toBe([$eligible])
    ->and($eligible->hasSummon($definition->id))->toBeTrue()
    ->and($excluded->hasSummon($definition->id))->toBeFalse()
    ->and(BattleCommandCatalog::canUseSummonAction($eligible, $party, 'Synthetic Call'))->toBeTrue();
  $party->assertSummonAssignments([$definition]);
});

it('restores legal ownership by current actor id and rejects excluded ownership despite display collisions', function () {
  $definition = createWielderIdentitySummon(['actor.allowed']);
  installWielderIdentityCatalog($definition);
  $original = createWielderIdentityDefinition('actor.allowed', 'Old Display')->createCharacter();
  $original->assignSummon($definition->id);
  $saved = $original->toArray();
  $saved['stats']['currentHp'] = 37;
  $store = new ActorStore(definitions: [
    createWielderIdentityDefinition('actor.allowed', 'New Display'),
    createWielderIdentityDefinition('actor.excluded', 'New Display'),
  ]);
  $restored = $store->require($saved['actorId'], 'restoring synthetic summon holder')->createCharacter($saved);
  $party = new Party();
  $party->addMember($restored);
  $party->assertSummonAssignments([$definition]);
  expect($restored->name)->toBe('New Display')
    ->and($restored->actorId)->toBe('actor.allowed')
    ->and($restored->stats->currentHp)->toBe(37)
    ->and($restored->hasSummon($definition->id))->toBeTrue()
    ->and(BattleCommandCatalog::buildOptions($restored, $party, 'Summon'))->toHaveCount(1)
    ->and(BattleCommandCatalog::canUseSummonAction($restored, $party, 'Synthetic Call'))->toBeTrue();

  $excludedSave = [...$saved, 'actorId' => 'actor.excluded', 'name' => 'actor.allowed'];
  $excluded = $store->require($excludedSave['actorId'], 'restoring synthetic excluded holder')->createCharacter($excludedSave);
  $party->addMember($excluded);
  expect($excluded->actorId)->toBe('actor.excluded')
    ->and($excluded->name)->toBe('New Display')
    ->and(BattleCommandCatalog::buildOptions($excluded, $party, 'Summon'))->toBe([])
    ->and(BattleCommandCatalog::canUseSummonAction($excluded, $party, 'Synthetic Call'))->toBeFalse()
    ->and(fn() => $party->assertSummonAssignments([$definition]))
    ->toThrow(SummonAssignmentException::class, 'not eligible');
});
