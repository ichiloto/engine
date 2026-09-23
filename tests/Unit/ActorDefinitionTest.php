<?php

use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use Ichiloto\Engine\Util\Stores\ActorStore;

function foundationActorDefinition(): ActorDefinition
{
  $store = new ActorStore(dirname(__DIR__) . '/Fixtures/Actors');

  return $store->require('actor.hero', 'loading the project-backed actor test fixture');
}

it('requires explicit actor identities and refuses to mutate an established identity', function () {
  foreach ([['name' => 'Hero'], ['id' => '', 'name' => 'Hero'], ['id' => 42, 'name' => 'Hero']] as $data) {
    expect(fn() => ActorDefinition::fromArray($data, 'Actors/Hero.php'))
      ->toThrow(InvalidArgumentException::class, 'Actors/Hero.php must declare an explicit non-empty actor id');
  }
  $definition = ActorDefinition::fromArray(['id' => 'actor.hero', 'name' => 'Hero']);
  expect(fn() => $definition->id = 'renamed')->toThrow(Error::class);
  expect($definition->id)->toBe('actor.hero');
});

it('uses ids alone despite duplicate display names and names matching another id', function () {
  $data = foundationActorDefinition()->data();
  $store = new ActorStore(definitions: [
    ActorDefinition::fromArray([...$data, 'id' => 'first', 'name' => 'second']),
    ActorDefinition::fromArray([...$data, 'id' => 'second', 'name' => 'Shared']),
    ActorDefinition::fromArray([...$data, 'id' => 'third', 'name' => 'Shared']),
  ]);
  expect($store->require('second', 'new party')->id)->toBe('second')
    ->and($store->has('Shared'))->toBeFalse()
    ->and($store->canonicalId('first'))->toBe('first');
  expect(fn() => $store->set('file-alias', new ActorDefinition('fourth', $data)))
    ->toThrow(InvalidArgumentException::class, 'aliases are not supported');
  $saved = $store->require('first', 'saving')->createCharacter()->toArray();
  $restored = $store->require($saved['actorId'], 'loading')->createCharacter($saved);
  expect($restored->actorId)->toBe('first')->and($restored->name)->toBe('second');
});

it('loads a legacy file provisionally without writing and drops name and file aliases after migration', function () {
  $root = sys_get_temp_dir() . '/ichiloto-legacy-actor-' . bin2hex(random_bytes(6));
  mkdir($root);
  $path = $root . '/unrelated-filename.php';
  $data = foundationActorDefinition()->data();
  unset($data['id']);
  $data['name'] = 'Legacy Hero';
  $source = '<?php return ' . var_export(['data' => $data], true) . ';';
  file_put_contents($path, $source);
  \Ichiloto\Engine\Util\Debug::configure(['log_directory' => $root]);
  try {
    $store = new ActorStore($root);
    expect(file_get_contents($path))->toBe($source)
      ->and($store->has('unrelated-filename'))->toBeFalse();
    $saved = $store->require('Legacy Hero', 'loading legacy project')->createCharacter()->toArray();
    $saved['stats']['currentHp'] = 23;
    expect(file_get_contents($root . '/warning.log'))->toContain($path, 'provisional id', 'before renaming', 'No project file was changed');
    $data['id'] = 'Legacy Hero';
    $data['name'] = 'Renamed Hero';
    file_put_contents($path, '<?php return ' . var_export(['data' => $data], true) . ';');
    $store = new ActorStore($root);
    expect($store->has('Renamed Hero'))->toBeFalse()->and($store->has('unrelated-filename'))->toBeFalse();
    $restored = $store->require($saved['actorId'], 'loading migrated project')->createCharacter($saved);
    expect($restored->actorId)->toBe('Legacy Hero')->and($restored->name)->toBe('Renamed Hero')
      ->and($restored->stats->currentHp)->toBe(23);
    foreach (['', null, 42] as $invalidId) {
      $data['id'] = $invalidId;
      file_put_contents($path, '<?php return ' . var_export(['data' => $data], true) . ';');
      expect(fn() => new ActorStore($root))->toThrow(InvalidArgumentException::class, 'explicit non-empty actor id');
    }
  } finally {
    foreach (glob($root . '/*') ?: [] as $file) { unlink($file); }
    rmdir($root);
    \Ichiloto\Engine\Util\Debug::configure();
  }
});

it('reconstructs a renamed actor from the same authored id and saved mutable state', function () {
  $data = foundationActorDefinition()->data();
  $saved = foundationActorDefinition()->createCharacter()->toArray();
  $saved['stats']['currentHp'] = 37;
  $data['name'] = 'Hero Renamed';
  $store = new ActorStore(definitions: [ActorDefinition::fromArray($data)]);
  $restored = $store->require($saved['actorId'], 'restoring renamed actor')->createCharacter($saved);
  expect($restored->actorId)->toBe('actor.hero')->and($restored->name)->toBe('Hero Renamed')
    ->and($restored->stats->currentHp)->toBe(37)->and($restored->toArray()['actorId'])->toBe('actor.hero');
  $beat = ['actor' => 'actor.hero', 'emotion' => 'Concerned'];
  $speaker = \Ichiloto\Engine\Field\SkitSpeaker::getFromBeat($beat, $store);
  $catalogue = new \Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog([
    'actor.hero' => ['emotions' => ['Concerned' => 'hero-concerned.png']],
  ]);
  $presentation = \Ichiloto\Engine\Field\SkitBeatPresentation::getFromBeat(
    dirname(__DIR__) . '/Fixtures', 'rename', $beat, $catalogue, $speaker->actorId);
  expect($speaker->name)->toBe('Hero Renamed')->and($speaker->errors)->toBeEmpty()
    ->and($presentation->emotion)->toBe('Concerned');
});

it('reconstructs fixed actor naturals from project data and defaults old saves to the project variant', function () {
  $definition = foundationActorDefinition();
  $restored = $definition->createCharacter([
    'name' => 'Hero',
    'currentExp' => 12,
    'stats' => [
      'currentHp' => 37,
      'currentMp' => 4,
      'currentAp' => 2,
      'totalHp' => 100,
      'totalMp' => 10,
      'totalAp' => 3,
      'attack' => 8,
      'defence' => 7,
      'magicAttack' => 6,
      'magicDefence' => 5,
      'speed' => 4,
      'grace' => 3,
      'evasion' => 2,
    ],
    'role' => 'Hero',
    'equipment' => [],
    'summons' => [],
    'abilities' => [],
    'magic' => [],
    'states' => [],
    'permanentGrowth' => [],
  ], '/tmp/old-v4.iedata');

  expect($restored->naturalVariantId)->toBe('standard')
    ->and($restored->actorNaturalAdjustments)->toBe(['maxHp' => 5])
    ->and($restored->stats->currentHp)->toBe(37)
    ->and($restored->stats->currentMp)->toBe(4)
    ->and($restored->toArray())->not->toHaveKey('actorNaturalAdjustments')
    ->and($restored->toArray()['naturalVariantId'])->toBe('standard');
});

it('round trips a runtime-selected natural variant by identity without saving its scalars', function () {
  $definition = foundationActorDefinition();
  $selected = $definition->createCharacter(['naturalVariantId' => 'alternate'], '/tmp/current-v4.iedata');
  $saved = $selected->toArray();
  $restored = $definition->createCharacter($saved, '/tmp/current-v4.iedata');

  expect($saved['naturalVariantId'])->toBe('alternate')
    ->and($saved['stats'])->toBeArray()
    ->and($saved)->not->toHaveKey('actorNaturalAdjustments')
    ->and($restored->naturalVariantId)->toBe('alternate')
    ->and($restored->actorNaturalAdjustments)->toBe(['maxHp' => 5, 'attack' => 3]);
});

it('restores current resources from legacy Stats-object save payloads', function () {
  $restored = foundationActorDefinition()->createCharacter([
    'name' => 'Hero',
    'stats' => new Stats(
      currentHp: 37,
      currentMp: 4,
      currentAp: 2,
      totalHp: 100,
      totalMp: 10,
      totalAp: 3,
    ),
  ], '/tmp/legacy-stats-object.iedata');

  expect($restored->stats->currentHp)->toBe(37)
    ->and($restored->stats->currentMp)->toBe(4)
    ->and($restored->stats->currentAp)->toBe(2);
});

it('fails actor variant compatibility with actor variant and save context', function () {
  expect(fn() => foundationActorDefinition()->createCharacter(
    ['naturalVariantId' => 'removed-variant'],
    '/saves/file-03.iedata',
  ))->toThrow(UnresolvedSaveReferenceException::class, 'Actor "actor.hero" references unknown natural variant "removed-variant" while loading save /saves/file-03.iedata');
});

it('fails missing actor definitions with actor variant and save context', function () {
  $store = (new ReflectionClass(ActorStore::class))->newInstanceWithoutConstructor();
  $context = 'loading actor "actor.removed" with natural variant "variant.saved" from save /saves/file-04.iedata';

  expect(fn() => $store->require('actor.removed', $context))->toThrow(
    UnresolvedSaveReferenceException::class,
    'Actor definition "actor.removed" cannot be resolved while ' . $context,
  );
});
