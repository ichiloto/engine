<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;
use Ichiloto\Engine\Exceptions\MissingSaveMigrationException;
use Ichiloto\Engine\Exceptions\TombstonedSaveReferenceException;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use Ichiloto\Engine\Exceptions\UnsupportedContentVersionException;
use Ichiloto\Engine\Exceptions\UnsupportedSaveSchemaException;
use Ichiloto\Engine\Exceptions\WrongProjectSaveException;
use Ichiloto\Engine\IO\SaveCompatibility\ContentMigrationInterface;
use Ichiloto\Engine\IO\SaveCompatibility\PostResolutionContentMigrationInterface;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline;
use Ichiloto\Engine\IO\SaveCompatibility\SaveContentResolver;
use Ichiloto\Engine\IO\SaveCompatibility\SaveHydrationContext;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;

class SaveCompatibilityTestGame extends Game
{
  public function __construct()
  {
  }

  public function __destruct()
  {
  }
}

class RecordingContentVersion0To1 implements ContentMigrationInterface
{
  /** @var string[] */
  public static array $eventsSeen = [];

  public function migrate(array $payload): array
  {
    $config = $payload['config'] ?? null;

    if ($config instanceof GameConfig) {
      self::$eventsSeen = $config->gameState['storyEvents'] ?? [];
    }

    return $payload;
  }
}

class RecordingPostResolutionContentVersion0To1 implements ContentMigrationInterface, PostResolutionContentMigrationInterface
{
  public static bool $actorWasDeferred = false;
  public static bool $actorWasHydrated = false;

  public function migrate(array $payload): array
  {
    $config = $payload['config'] ?? null;
    $actor = $config instanceof GameConfig ? ($config->party->members->toArray()[0] ?? null) : null;
    self::$actorWasDeferred = $actor instanceof Character && $actor->getDeferredSaveData() !== null;

    return $payload;
  }

  public function migrateResolved(GameConfig $config): void
  {
    $actor = $config->party->members->toArray()[0] ?? null;
    self::$actorWasHydrated = $actor instanceof Character && $actor->getDeferredSaveData() === null;
  }
}

class SaveCompatibilitySceneStub extends GameScene
{
  public function __construct(private GameConfig $snapshot)
  {
    $this->party = $snapshot->party;
  }

  public function createSnapshot(int $playTimeSeconds = 0): GameConfig
  {
    return $this->snapshot;
  }
}

function makeCompatibilityManifest(array $overrides = []): SaveCompatibilityManifest
{
  return SaveCompatibilityManifest::fromArray('ichiloto/test-project', array_replace_recursive([
    'contentVersion' => 0,
    'migrations' => [],
    'aliases' => [],
    'tombstones' => [],
  ], $overrides), 'SaveCompatibilityTest manifest');
}

function makeCompatibilityConfig(string $mapId = 'old-map', array $events = []): GameConfig
{
  $party = new Party();
  $party->location = new PartyLocation('Test Field', 'Test Region');
  $party->addMember(new Character('Hero', 0, new Stats(currentHp: 80, totalHp: 100)));

  return new GameConfig(
    mapId: $mapId,
    party: $party,
    playerPosition: new Vector2(7, 9),
    playerShape: new Rect(0, 0, 1, 1),
    playerHeading: MovementHeading::EAST,
    events: $events,
    playTimeSeconds: 120,
  );
}

function makeCompatibilitySlot(string $path, int $slot = 1): SaveSlot
{
  return new SaveSlot(
    slot: $slot,
    path: $path,
    isEmpty: false,
    locationName: 'Test Field',
    leaderName: 'Hero',
    leaderLevel: 1,
    playTimeSeconds: 120,
    savedAt: 1234567890,
  );
}

/** Exercises both raw decoded saves and actors already hydrated by migrations. */
function resolveCompatibilityActor(array $savedState, bool $deferred, SaveCompatibilityManifest $manifest): Character
{
  $member = new ReflectionClass(Character::class)->newInstanceWithoutConstructor();

  if ($deferred) {
    SaveHydrationContext::begin();
  }

  try {
    $member->__unserialize($savedState);
  } finally {
    if ($deferred) {
      SaveHydrationContext::end();
    }
  }

  expect($member->getDeferredSaveData() !== null)->toBe($deferred);
  $config = makeCompatibilityConfig();
  $config->party->members[0] = $member;
  $resolved = new SaveContentResolver($manifest, '/saves/actor-identity.iedata')->resolve($config);

  return $resolved->party->members->toArray()[0];
}

function writeCompatibilityPayload(string $path, array $payload): void
{
  if (! is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
  file_put_contents($path, 'IED1' . gzencode(serialize($payload), 9));
}

function decodeCompatibilityPayload(string $path): array
{
  $contents = (string) file_get_contents($path);
  $decoded = unserialize((string) gzdecode(substr($contents, 4)), ['allowed_classes' => true]);

  return is_array($decoded) ? $decoded : [];
}

function cleanupCompatibilityManager(SaveManager $manager): void
{
  $saveDirectory = dirname($manager->getSlotPath(1));
  $quickDirectory = dirname($manager->getQuickSavePath('quick'));

  foreach ($manager->getSaveFiles(true) as $path) {
    if (is_file($path)) {
      unlink($path);
    }
  }

  if (is_dir($quickDirectory)) {
    rmdir($quickDirectory);
  }

  if (is_dir($saveDirectory)) {
    rmdir($saveDirectory);
  }
}

it('writes the v1 envelope through normal quick and rotating autosave surfaces', function () {
  $slug = 'save-compatibility-surfaces-' . uniqid();
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest(),
  );
  $scene = new SaveCompatibilitySceneStub(makeCompatibilityConfig('field/current'));

  $manager->save($scene, 1);
  $manager->quickSave($scene);
  $manager->autoSave($scene);
  $manager->autoSave($scene);
  $manager->autoSave($scene);
  $manager->autoSave($scene);

  $normal = decodeCompatibilityPayload($manager->getSlotPath(1));
  $quick = decodeCompatibilityPayload($manager->getQuickSavePath('quick'));
  $autos = glob(dirname($manager->getQuickSavePath('quick')) . '/auto-*.iedata') ?: [];

  expect($normal)->toMatchArray([
    'schemaVersion' => 1,
    'contentVersion' => 0,
    'projectId' => 'ichiloto/test-project',
  ])->and($normal['payload'])->toHaveKeys(['slot', 'config'])
    ->and($quick['schemaVersion'])->toBe(1)
    ->and($autos)->toHaveCount(3)
    ->and($manager->getSaveSlots(1)[0]->isLoadable)->toBeTrue()
    ->and($manager->getLatestLoadableSaveFile(true))->not->toBeNull();

  foreach ($autos as $auto) {
    expect($manager->loadSaveFile($auto)->config->mapId)->toBe('field/current');
  }

  cleanupCompatibilityManager($manager);
});

it('binds relocated save summaries to the destination file and slot', function (bool $versioned, bool $sourceExists, int $sourceSlot) {
  $slug = 'save-compatibility-relocated-' . uniqid();
  $manifest = makeCompatibilityManifest();
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    $manifest,
  );
  $source = $manager->getSlotPath(1);
  $slot = makeCompatibilitySlot($source, $sourceSlot);
  $payload = ['slot' => $slot, 'config' => makeCompatibilityConfig('copied-map')];
  if ($versioned) {
    $payload = new SaveCompatibilityPipeline($manifest)->createEnvelope($slot, $payload['config']);
  }
  writeCompatibilityPayload($source, $payload);
  $copies = [
    $manager->getSlotPath(2) => 2,
    $manager->getQuickSavePath('quick') => SaveManager::QUICK_SAVE_SLOT,
    $manager->getQuickSavePath('auto-01') => SaveManager::AUTO_SAVE_SLOT,
    $manager->getQuickSavePath('backup') => $sourceSlot,
  ];
  mkdir(dirname($manager->getQuickSavePath('quick')), 0777, true);

  try {
    foreach ($copies as $path => $destinationSlot) {
      copy($source, $path);
    }
    if ($sourceExists) {
      writeCompatibilityPayload($source, ['slot' => $slot, 'config' => makeCompatibilityConfig('different-map')]);
    } else {
      unlink($source);
    }
    foreach ($copies as $path => $destinationSlot) {
      $before = hash_file('sha256', $path);
      $loaded = $manager->loadSaveFile($path);
      expect($loaded->slot->__serialize())->toBe(array_replace($slot->__serialize(), ['path' => $path, 'slot' => $destinationSlot]))
        ->and($manager->loadSaveFile($loaded->slot->path)->config->mapId)->toBe('copied-map')
        ->and(hash_file('sha256', $path))->toBe($before);
    }
    // Continue follows the summary path, not necessarily the caller's original path.
    expect($manager->loadSaveFile($manager->getSaveSlots(2)[1]->path)->config->mapId)->toBe('copied-map');
    expect($manager->loadSlot(2)->slot->slot)->toBe(2);

    // The save menu writes using the enumerated summary's slot number.
    $sourceHash = $sourceExists ? hash_file('sha256', $source) : null;
    $manager->save(new SaveCompatibilitySceneStub(makeCompatibilityConfig('updated-map')), $manager->getSaveSlots(2)[1]->slot);
    expect($manager->loadSlot(2)->config->mapId)->toBe('updated-map')
      ->and($sourceExists ? hash_file('sha256', $source) : file_exists($source))->toBe($sourceHash ?? false);
  } finally {
    cleanupCompatibilityManager($manager);
  }
})->with([false, true])->with([false, true])->with([1, SaveManager::QUICK_SAVE_SLOT, SaveManager::AUTO_SAVE_SLOT]);

it('runs schema migration before project content migration without rewriting the source', function () {
  $slug = 'save-compatibility-legacy-' . uniqid();
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest([
      'contentVersion' => 1,
      'migrations' => [[
        'from' => 0,
        'to' => 1,
        'class' => RecordingContentVersion0To1::class,
      ]],
    ]),
  );
  $path = $manager->getSlotPath(1);
  writeCompatibilityPayload($path, [
    'slot' => makeCompatibilitySlot($path),
    'config' => makeCompatibilityConfig(events: ['legacy-event']),
  ]);
  $before = hash_file('sha256', $path);
  RecordingContentVersion0To1::$eventsSeen = [];

  $loaded = $manager->loadSaveFile($path);

  expect(RecordingContentVersion0To1::$eventsSeen)->toBe(['legacy-event'])
    ->and($loaded->config->gameState['storyEvents'])->toBe(['legacy-event'])
    ->and(hash_file('sha256', $path))->toBe($before)
    ->and(decodeCompatibilityPayload($path))->not->toHaveKey('schemaVersion');

  cleanupCompatibilityManager($manager);
});

it('offers content migrations a post-resolution phase only after actors hydrate', function () {
  $slug = 'save-compatibility-post-resolution-' . uniqid();
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest([
      'contentVersion' => 1,
      'migrations' => [[
        'from' => 0,
        'to' => 1,
        'class' => RecordingPostResolutionContentVersion0To1::class,
      ]],
    ]),
  );
  $path = $manager->getSlotPath(1);
  writeCompatibilityPayload($path, [
    'slot' => makeCompatibilitySlot($path),
    'config' => makeCompatibilityConfig(),
  ]);
  RecordingPostResolutionContentVersion0To1::$actorWasDeferred = false;
  RecordingPostResolutionContentVersion0To1::$actorWasHydrated = false;

  $manager->loadSaveFile($path);

  expect(RecordingPostResolutionContentVersion0To1::$actorWasDeferred)->toBeTrue()
    ->and(RecordingPostResolutionContentVersion0To1::$actorWasHydrated)->toBeTrue();

  cleanupCompatibilityManager($manager);
});

it('reports missing schema and content migration steps', function () {
  $path = '/tmp/ichiloto-missing-migration.iedata';
  $legacy = serialize([
    'slot' => makeCompatibilitySlot($path),
    'config' => makeCompatibilityConfig(),
  ]);
  $schemaPipeline = new SaveCompatibilityPipeline(makeCompatibilityManifest(), []);

  expect(fn() => $schemaPipeline->load($legacy, $path))
    ->toThrow(MissingSaveMigrationException::class, 'missing schema migration 0 to 1');

  $contentPipeline = new SaveCompatibilityPipeline(makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [],
  ]));

  expect(fn() => $contentPipeline->load($legacy, $path))
    ->toThrow(MissingSaveMigrationException::class, 'missing content migration 0 to 1');
});

it('rejects future schema future content and wrong-project saves readably', function () {
  $slug = 'save-compatibility-rejections-' . uniqid();
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest(),
  );
  $path = $manager->getSlotPath(1);
  $base = [
    'schemaVersion' => 1,
    'contentVersion' => 0,
    'projectId' => 'ichiloto/test-project',
    'payload' => [
      'slot' => makeCompatibilitySlot($path),
      'config' => makeCompatibilityConfig(),
    ],
  ];

  writeCompatibilityPayload($path, array_replace($base, ['schemaVersion' => 2]));
  expect(fn() => $manager->loadSaveFile($path))
    ->toThrow(UnsupportedSaveSchemaException::class, 'newer engine is required');

  writeCompatibilityPayload($path, array_replace($base, ['contentVersion' => 1]));
  expect(fn() => $manager->loadSaveFile($path))
    ->toThrow(UnsupportedContentVersionException::class, 'newer game build is required');

  writeCompatibilityPayload($path, array_replace($base, ['projectId' => 'ichiloto/another-game']));
  expect(fn() => $manager->loadSaveFile($path))
    ->toThrow(WrongProjectSaveException::class, 'ichiloto/another-game');

  cleanupCompatibilityManager($manager);
});

it('resolves map aliases in current visited and one-shot event identities', function () {
  $slug = 'save-compatibility-map-alias-' . uniqid();
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest([
      'aliases' => [
        'maps' => [['from' => 'old-map', 'to' => 'new-map']],
      ],
    ]),
  );
  $path = $manager->getSlotPath(1);
  $config = makeCompatibilityConfig();
  $data = $config->getSaveCompatibilityData();
  $data['gameState'] = [
    'visitedMaps' => ['old-map' => true],
    'completedEvents' => ['old-map:treasure-west' => true],
  ];
  $config->applySaveCompatibilityData($data);
  writeCompatibilityPayload($path, [
    'schemaVersion' => 1,
    'contentVersion' => 0,
    'projectId' => 'ichiloto/test-project',
    'payload' => ['slot' => makeCompatibilitySlot($path), 'config' => $config],
  ]);

  $loaded = $manager->loadSaveFile($path)->config;

  expect($loaded->mapId)->toBe('new-map')
    ->and($loaded->gameState['visitedMaps'])->toBe(['new-map' => true])
    ->and($loaded->gameState['completedEvents'])->toBe(['new-map:treasure-west' => true]);

  cleanupCompatibilityManager($manager);
});

it('resolves actor aliases before reconstructing current project definitions', function () {
  $slug = 'save-compatibility-actor-alias-' . uniqid();
  $actorStore = new ActorStore(dirname(__DIR__) . '/Fixtures/Actors');
  ConfigStore::put(ActorStore::class, $actorStore);
  $manager = new SaveManager(
    new SaveCompatibilityTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest([
      'aliases' => [
        'actors' => [['from' => 'Legacy Hero', 'to' => 'actor.hero']],
      ],
    ]),
  );
  $config = makeCompatibilityConfig();
  $config->party->members[0] = new Character(
    'Legacy Hero',
    0,
    new Stats(
      currentHp: 63,
      currentMp: 7,
      currentAp: 2,
      totalHp: 100,
      totalMp: 10,
      totalAp: 3,
    ),
  );

  try {
    $manager->save(new SaveCompatibilitySceneStub($config), 1);
    $restored = $manager->loadSlot(1)->config->party->members->toArray()[0];

    expect($restored)->toBeInstanceOf(Character::class)
      ->and($restored->name)->toBe('Hero')
      ->and($restored->naturalVariantId)->toBe('standard')
      ->and($restored->actorNaturalAdjustments)->toBe(['maxHp' => 5])
      ->and($restored->stats->currentHp)->toBe(63)
      ->and($restored->stats->currentMp)->toBe(7)
      ->and($restored->stats->currentAp)->toBe(2)
      ->and($restored->toArray())->not->toHaveKey('actorNaturalAdjustments');
  } finally {
    cleanupCompatibilityManager($manager);
    ConfigStore::remove(ActorStore::class);
  }
});

describe('saved actor identities', function () {
  beforeEach(function () {
    $this->previousActorStore = ConfigStore::has(ActorStore::class) ? ConfigStore::get(ActorStore::class) : null;
    $this->actorStore = new ActorStore(dirname(__DIR__) . '/Fixtures/Actors');
    ConfigStore::put(ActorStore::class, $this->actorStore);
    $this->savedActor = new Character(
      'Legacy Hero',
      12,
      new Stats(currentHp: 37, currentMp: 4, currentAp: 2, totalHp: 100, totalMp: 10, totalAp: 3),
      naturalVariantId: 'alternate',
      actorId: 'actor.hero',
    );
  });

  afterEach(function () {
    if ($this->previousActorStore !== null) {
      ConfigStore::put(ActorStore::class, $this->previousActorStore);
    } else {
      ConfigStore::remove(ActorStore::class);
    }
  });

  it('prefers stable IDs over renamed or reused display names after actor alias migration', function (bool $deferred, bool $collision, bool $alias) {
    if ($collision) {
      $data = $this->actorStore->require('actor.hero', 'building the collision fixture')->data();
      $data['id'] = 'actor.decoy';
      $data['name'] = 'Legacy Hero';
      $this->actorStore->set('actor.decoy', ActorDefinition::fromArray($data));
    }

    $saved = $this->savedActor->toArray();
    $saved['actorId'] = $alias ? 'actor.old-hero' : $saved['actorId'];
    $manifest = makeCompatibilityManifest([
      'aliases' => ['actors' => [
        ['from' => 'actor.old-hero', 'to' => 'actor.intermediate-hero'],
        ['from' => 'actor.intermediate-hero', 'to' => 'actor.hero'],
      ]],
    ]);

    $restored = resolveCompatibilityActor($saved, $deferred, $manifest);

    expect($restored->actorId)->toBe('actor.hero')
      ->and($restored->name)->toBe('Hero')
      ->and($restored->currentExp)->toBe(12)
      ->and($restored->stats->currentHp)->toBe(37)
      ->and($restored->stats->currentMp)->toBe(4)
      ->and($restored->stats->currentAp)->toBe(2)
      ->and($restored->naturalVariantId)->toBe('alternate')
      ->and($restored->actorNaturalAdjustments)->toBe(['maxHp' => 5, 'attack' => 3])
      ->and($restored->getDeferredSaveData())->toBeNull()
      ->and($restored->toArray()['actorId'])->toBe('actor.hero');
  })->with(['deferred' => [true], 'hydrated' => [false]])
    ->with(['renamed' => [false], 'name reused by another actor' => [true]])
    ->with(['unchanged ID' => [false], 'aliased ID' => [true]]);

  it('does not interpret a stable actor display name as an alias or tombstone', function (bool $deferred, array $compatibility) {
    $restored = resolveCompatibilityActor($this->savedActor->toArray(), $deferred, makeCompatibilityManifest($compatibility));

    expect($restored->actorId)->toBe('actor.hero')
      ->and($restored->name)->toBe('Hero');
  })->with(['deferred' => [true], 'hydrated' => [false]])->with([
    'display-name alias' => [['aliases' => ['actors' => [['from' => 'Legacy Hero', 'to' => 'actor.missing']]]]],
    'display-name tombstone' => [['tombstones' => ['actors' => ['Legacy Hero']]]],
  ]);

  it('removes inferred display name lookup from legacy saves and requires an explicit identity migration', function (bool $deferred, ?string $actorId, bool $alias) {
    $saved = $this->savedActor->toArray();
    $saved['name'] = $alias ? 'Legacy Hero' : 'Hero';
    unset($saved['actorId']);

    if ($actorId !== null) {
      $saved['actorId'] = $actorId;
    }

    $manifest = makeCompatibilityManifest([
      'aliases' => ['actors' => [['from' => 'Legacy Hero', 'to' => 'actor.hero']]],
    ]);
    if (! $alias) {
      expect(fn() => resolveCompatibilityActor($saved, $deferred, $manifest))
        ->toThrow(UnresolvedSaveReferenceException::class, 'Actor definition "Hero" cannot be resolved');
      return;
    }
    $restored = resolveCompatibilityActor($saved, $deferred, $manifest);

    expect($restored->actorId)->toBe('actor.hero')
      ->and($restored->name)->toBe('Hero')
      ->and($restored->stats->currentHp)->toBe(37)
      ->and($restored->toArray()['actorId'])->toBe('actor.hero');
  })->with(['deferred' => [true], 'hydrated' => [false]])
    ->with(['missing ID' => [null], 'empty ID' => [''], 'blank ID' => ['  ']])
    ->with(['current name' => [false], 'legacy alias' => [true]]);

  it('rejects missing stable IDs rather than falling back to a valid display name', function (bool $deferred, bool $alias) {
    $saved = $this->savedActor->toArray();
    $saved['name'] = 'Hero';
    $saved['actorId'] = $alias ? 'actor.old-hero' : 'actor.removed';
    $manifest = makeCompatibilityManifest([
      'aliases' => ['actors' => [['from' => 'actor.old-hero', 'to' => 'actor.removed']]],
    ]);

    expect(fn() => resolveCompatibilityActor($saved, $deferred, $manifest))->toThrow(
      UnresolvedSaveReferenceException::class,
      'Actor definition "actor.removed" cannot be resolved while loading actor "actor.removed" with natural variant "alternate" from save /saves/actor-identity.iedata.',
    );
  })->with(['deferred' => [true], 'hydrated' => [false]])
    ->with(['missing ID' => [false], 'alias to missing ID' => [true]]);

  it('rejects tombstoned stable IDs even when the display name still resolves', function (bool $deferred) {
    $saved = $this->savedActor->toArray();
    $saved['name'] = 'Hero';
    $manifest = makeCompatibilityManifest(['tombstones' => ['actors' => ['actor.hero']]]);

    expect(fn() => resolveCompatibilityActor($saved, $deferred, $manifest))->toThrow(
      TombstonedSaveReferenceException::class,
      'Save /saves/actor-identity.iedata references tombstoned actors identity "actor.hero"',
    );
  })->with(['deferred' => [true], 'hydrated' => [false]]);

  it('migrates IDs without replacing distinct display names when no actor store is configured', function (bool $deferred) {
    ConfigStore::remove(ActorStore::class);
    $saved = $this->savedActor->toArray();
    $saved['actorId'] = 'actor.old-hero';
    $restored = resolveCompatibilityActor($saved, $deferred, makeCompatibilityManifest([
      'aliases' => ['actors' => [['from' => 'actor.old-hero', 'to' => 'actor.hero']]],
    ]));

    expect($restored->actorId)->toBe('actor.hero')
      ->and($restored->name)->toBe('Legacy Hero')
      ->and($restored->stats->currentHp)->toBe(37)
      ->and($restored->toArray()['actorId'])->toBe('actor.hero');
  })->with(['deferred' => [true], 'hydrated' => [false]]);

  it('retains legacy name-alias behavior without an actor store and persists the migrated identity', function (bool $deferred) {
    ConfigStore::remove(ActorStore::class);
    $saved = $this->savedActor->toArray();
    unset($saved['actorId']);
    $restored = resolveCompatibilityActor($saved, $deferred, makeCompatibilityManifest([
      'aliases' => ['actors' => [['from' => 'Legacy Hero', 'to' => 'Hero']]],
    ]));

    expect($restored->actorId)->toBe('Hero')
      ->and($restored->name)->toBe('Hero')
      ->and($restored->toArray()['actorId'])->toBe('Hero');
  })->with(['deferred' => [true], 'hydrated' => [false]]);

  it('loads a serialized stable actor after a project display-name change through the save pipeline', function () {
    $config = makeCompatibilityConfig();
    $config->party->members[0] = $this->savedActor;
    $path = '/saves/renamed-actor.iedata';
    $pipeline = new SaveCompatibilityPipeline(makeCompatibilityManifest());
    $serialized = serialize($pipeline->createEnvelope(makeCompatibilitySlot($path), $config));

    $loaded = $pipeline->load($serialized, $path);
    $restored = $loaded->config->party->members->toArray()[0];
    $resaved = serialize($pipeline->createEnvelope($loaded->slot, $loaded->config));
    $reloaded = $pipeline->load($resaved, $path)->config->party->members->toArray()[0];

    expect($restored->actorId)->toBe('actor.hero')
      ->and($restored->name)->toBe('Hero')
      ->and($restored->stats->currentHp)->toBe(37)
      ->and($reloaded->toArray())->toEqual($restored->toArray());
  });
});

it('rejects tombstoned saved identities and invalid alias graphs', function () {
  $manifest = makeCompatibilityManifest([
    'tombstones' => ['maps' => ['removed-map']],
  ]);

  expect(fn() => $manifest->resolve(
    Ichiloto\Engine\IO\SaveCompatibility\ContentReferenceCategory::MAP,
    'removed-map',
    '/tmp/test.iedata'
  ))->toThrow(TombstonedSaveReferenceException::class, 'project-defined content migration is required');

  expect(fn() => makeCompatibilityManifest([
    'aliases' => ['maps' => [
      ['from' => 'a', 'to' => 'b'],
      ['from' => 'b', 'to' => 'a'],
    ]],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, 'contains a cycle');

  expect(fn() => makeCompatibilityManifest([
    'aliases' => ['maps' => [
      ['from' => 'a', 'to' => 'b'],
      ['from' => 'a', 'to' => 'c'],
    ]],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, 'maps "a" to both');
});

it('persists only persistent character states with remaining duration through character and party serialization', function () {
  $originalDirectory = getcwd();
  $project = dirname(__DIR__) . '/Support/Projects/SaveCompatibility';
  chdir($project);
  StateRegistry::reset();

  try {
    $persistent = StateRegistry::get('persistent-test');
    $transient = StateRegistry::get('transient-test');
    expect($persistent)->not->toBeNull()->and($transient)->not->toBeNull();

    $character = new Character('Reserve', 0, new Stats(currentHp: 90, totalHp: 100));
    $character->addState($persistent);
    $character->addState($transient);
    $character->tickStates();
    $restoredCharacter = unserialize(serialize($character));

    expect($restoredCharacter->hasState('persistent-test'))->toBeTrue()
      ->and($restoredCharacter->hasState('transient-test'))->toBeFalse()
      ->and($restoredCharacter->states[0]->remainingTurns)->toBe(4);

    $party = new Party();
    $party->addMember($character);
    $restoredParty = unserialize(serialize($party));
    $reserve = $restoredParty->members->toArray()[0];

    expect($reserve->hasState('persistent-test'))->toBeTrue()
      ->and($reserve->states[0]->remainingTurns)->toBe(4);
  } finally {
    StateRegistry::reset();
    chdir($originalDirectory);
  }
});

it('applies persistent-state aliases during complete save loading and fails on tombstones', function () {
  $originalDirectory = getcwd();
  $project = dirname(__DIR__) . '/Support/Projects/SaveCompatibility';
  chdir($project);
  StateRegistry::reset();

  try {
    $character = new Character('Reserve', 0, new Stats(currentHp: 90, totalHp: 100));
    $character->addState(StateRegistry::get('persistent-test'));
    $config = makeCompatibilityConfig('state-field');
    $config->party->addMember($character);
    $slug = 'state-alias-' . uniqid();
    $aliasManager = new SaveManager(
      new SaveCompatibilityTestGame(),
      "./{$slug}",
      "./{$slug}/quick",
      makeCompatibilityManifest([
        'aliases' => ['states' => [[
          'from' => 'persistent-test',
          'to' => 'renamed-persistent-test',
        ]]],
      ]),
    );
    $aliasManager->save(new SaveCompatibilitySceneStub($config), 1);
    $path = $aliasManager->getSlotPath(1);
    $restored = $aliasManager->loadSlot(1)->config->party->members->toArray()[1];

    expect($restored->hasState('renamed-persistent-test'))->toBeTrue()
      ->and($restored->states[0]->remainingTurns)->toBe(5);

    $tombstoneManager = new SaveManager(
      new SaveCompatibilityTestGame(),
      "./{$slug}-tombstone",
      "./{$slug}-tombstone/quick",
      makeCompatibilityManifest(['tombstones' => ['states' => ['persistent-test']]]),
    );
    $tombstonePath = $tombstoneManager->getSlotPath(1);
    if (! is_dir(dirname($tombstonePath))) { mkdir(dirname($tombstonePath), 0777, true); }
    file_put_contents($tombstonePath, file_get_contents($path));

    expect(fn() => $tombstoneManager->loadSlot(1))
      ->toThrow(TombstonedSaveReferenceException::class, 'tombstoned states identity "persistent-test"');

    cleanupCompatibilityManager($aliasManager);
    cleanupCompatibilityManager($tombstoneManager);
  } finally {
    StateRegistry::reset();
    chdir($originalDirectory);
  }
});
