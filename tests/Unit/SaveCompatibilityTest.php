<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;
use Ichiloto\Engine\Exceptions\MissingSaveMigrationException;
use Ichiloto\Engine\Exceptions\TombstonedSaveReferenceException;
use Ichiloto\Engine\Exceptions\UnsupportedContentVersionException;
use Ichiloto\Engine\Exceptions\UnsupportedSaveSchemaException;
use Ichiloto\Engine\Exceptions\WrongProjectSaveException;
use Ichiloto\Engine\IO\SaveCompatibility\ContentMigrationInterface;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;

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

function writeCompatibilityPayload(string $path, array $payload): void
{
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
