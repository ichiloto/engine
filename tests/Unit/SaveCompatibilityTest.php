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

/** A synthetic project migration: Mastery EXP progress becomes points before the character hydrates. */
class ConvertingMasteryContentVersion0To1 implements ContentMigrationInterface
{
  public function migrate(array $payload): array
  {
    $config = $payload['config'] ?? null;
    foreach ($config instanceof GameConfig ? $config->party->members->toArray() : [] as $member) {
      $raw = $member->getDeferredSaveData();
      $mastery = is_array($raw['mastery'] ?? null) ? $raw['mastery'] : [];
      $experience = intval($mastery['banked'] ?? 0) + array_sum(is_array($mastery['progress'] ?? null) ? $mastery['progress'] : []);
      $member->replaceDeferredSaveData([...$raw, 'mastery' => ['points' => intdiv($experience, 5), 'learned' => $mastery['learned'] ?? []]]);
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

it('writes the v1 envelope through normal quick and single autosave surfaces, removing rotating writes', function () {
  putSceneAudioConfig(['save' => ['autosave' => true]]);
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
  $autos = glob(dirname($manager->getQuickSavePath('quick')) . '/auto.iedata') ?: [];

  expect($normal)->toMatchArray([
    'schemaVersion' => 1,
    'contentVersion' => 0,
    'projectId' => 'ichiloto/test-project',
  ])->and($normal['payload'])->toHaveKeys(['slot', 'config'])
    ->and($quick['schemaVersion'])->toBe(1)
    ->and($autos)->toHaveCount(1)
    ->and($manager->getSaveSlots(1)[0]->isLoadable)->toBeTrue()
    ->and($manager->getLatestLoadableSaveFile(true))->not->toBeNull();

  foreach ($autos as $auto) {
    expect($manager->loadSaveFile($auto)->config->mapId)->toBe('field/current');
  }

  cleanupCompatibilityManager($manager);
  ConfigStore::remove(\Ichiloto\Engine\Util\Config\ProjectConfig::class);
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

it('lets a content migration convert a loading character\'s saved data before it hydrates', function () {
  $slug = 'save-compatibility-character-conversion-' . uniqid();
  $manager = new SaveManager(new SaveCompatibilityTestGame(), "./tests/Support/Data/{$slug}", "./tests/Support/Data/{$slug}/quick",
    makeCompatibilityManifest(['contentVersion' => 1, 'migrations' => [['from' => 0, 'to' => 1, 'class' => ConvertingMasteryContentVersion0To1::class]]]));
  $path = $manager->getSlotPath(1);
  $legacy = serialize(['studying' => 'spark', 'progress' => ['spark' => 40], 'banked' => 15, 'learned' => ['cut']]);
  $payload = serialize(['slot' => makeCompatibilitySlot($path), 'config' => makeCompatibilityConfig()]);
  $marker = 's:7:"mastery";a:0:{}';
  expect(substr_count($payload, $marker))->toBe(1);
  if (! is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
  file_put_contents($path, 'IED1' . gzencode(str_replace($marker, 's:7:"mastery";' . $legacy, $payload), 9));

  $hero = $manager->loadSaveFile($path)->config->party->members->toArray()[0];

  expect($hero->mastery->points)->toBe(11)
    ->and($hero->mastery->learned)->toBe(['cut'])
    ->and(fn() => $hero->replaceDeferredSaveData([]))->toThrow(LogicException::class, 'not awaiting save hydration');

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

it('moves a saved player position through declared map shifts in order', function (string $mapId, array $position, array $expected) {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 2,
    'migrations' => [
      [
        'from' => 0,
        'to' => 1,
        'mapShifts' => [
          ['map' => 'town', 'axis' => 'y', 'at' => 4, 'by' => 2],
          ['map' => 'town', 'axis' => 'x', 'at' => 7, 'by' => 3],
        ],
      ],
      [
        'from' => 1,
        'to' => 2,
        'mapShifts' => [['map' => 'town', 'axis' => 'y', 'at' => 11, 'by' => 1]],
      ],
    ],
    'aliases' => ['maps' => [['from' => 'old-town', 'to' => 'town']]],
  ]);
  $path = '/tmp/ichiloto-map-shift.iedata';
  $config = makeCompatibilityConfig($mapId);
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(...$position);
  $config->applySaveCompatibilityData($data);
  $legacy = serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]);

  $loaded = new SaveCompatibilityPipeline($manifest)->load($legacy, $path)->config;

  expect([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual($expected);
})->with([
  'beyond both lines, then the later row' => ['town', [9, 9], [12, 12]],
  'on the insertion lines themselves' => ['town', [7, 4], [10, 6]],
  'above and left of every line' => ['town', [6, 3], [6, 3]],
  'a renamed map follows its alias' => ['old-town', [0, 10], [0, 13]],
  'another map is untouched' => ['forest', [9, 9], [9, 9]],
]);

it('rejects malformed declarative map shift migrations', function (array $entry, string $message) {
  expect(fn() => makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [array_replace(['from' => 0, 'to' => 1], $entry)],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, $message);
})->with([
  'both kinds' => [
    ['class' => RecordingContentVersion0To1::class, 'mapShifts' => [['map' => 'a', 'axis' => 'y', 'at' => 0, 'by' => 1]]],
    'must declare either a class or declared position edits',
  ],
  'neither kind' => [[], 'must declare either a class or declared position edits'],
  'empty list' => [['mapShifts' => []], 'mapShifts must be a non-empty list'],
  'keyed list' => [['mapShifts' => ['a' => ['map' => 'a', 'axis' => 'y', 'at' => 0, 'by' => 1]]], 'non-empty list'],
  'not an array' => [['mapShifts' => ['y']], 'must be an array with map, axis, at and by'],
  'empty map' => [['mapShifts' => [['map' => ' ', 'axis' => 'y', 'at' => 0, 'by' => 1]]], 'map must be a non-empty string'],
  'unknown axis' => [['mapShifts' => [['map' => 'a', 'axis' => 'z', 'at' => 0, 'by' => 1]]], 'axis must be "x" or "y"'],
  'negative line' => [['mapShifts' => [['map' => 'a', 'axis' => 'x', 'at' => -1, 'by' => 1]]], 'at must be a non-negative integer'],
  'string line' => [['mapShifts' => [['map' => 'a', 'axis' => 'x', 'at' => '2', 'by' => 1]]], 'at must be a non-negative integer'],
  'zero count' => [['mapShifts' => [['map' => 'a', 'axis' => 'x', 'at' => 0, 'by' => 0]]], 'by must be a positive integer'],
]);

it('moves saved positions through removed columns and rows using the declared landing', function (string $mapId, array $position, array $expected) {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0,
      'to' => 1,
      'mapRemovals' => [
        ['map' => 'town', 'axis' => 'x', 'at' => 4, 'count' => 2, 'to' => [1, 2]],
        ['map' => 'old-town', 'axis' => 'y', 'at' => 6, 'count' => 3, 'to' => [3, 4]],
        ['map' => 'edge', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [0, 0]],
      ],
    ]],
    'aliases' => ['maps' => [['from' => 'old-town', 'to' => 'town']]],
  ]);
  $path = '/tmp/ichiloto-map-removal.iedata';
  $config = makeCompatibilityConfig($mapId);
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(...$position);
  $config->applySaveCompatibilityData($data);
  $legacy = serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]);

  $loaded = new SaveCompatibilityPipeline($manifest)->load($legacy, $path)->config;

  expect([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual($expected);
})->with([
  'before both ranges' => ['town', [3, 5], [3, 5]],
  'first removed column' => ['town', [4, 5], [1, 2]],
  'last removed column' => ['town', [5, 5], [1, 2]],
  'first column after removal' => ['town', [6, 5], [4, 5]],
  'first removed row' => ['town', [3, 6], [3, 4]],
  'last removed row' => ['town', [3, 8], [3, 4]],
  'first row after removal' => ['town', [3, 9], [3, 6]],
  'beyond both ranges' => ['town', [9, 11], [7, 8]],
  'a landing follows the next removal' => ['town', [9, 7], [3, 4]],
  'save and edit aliases both resolve' => ['old-town', [9, 11], [7, 8]],
  'another map is unchanged' => ['forest', [9, 11], [9, 11]],
  'zero-index removed column' => ['edge', [0, 2], [0, 0]],
  'after zero-index removed column' => ['edge', [1, 2], [0, 2]],
]);

it('applies declared shifts then removals then relocations regardless of key order', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 2,
    'migrations' => [
      [
        'from' => 0, 'to' => 1,
        'relocations' => [['map' => 'town', 'cells' => [[6, 3]], 'to' => [2, 4]]],
        'mapRemovals' => [['map' => 'town', 'axis' => 'x', 'at' => 4, 'count' => 2, 'to' => [1, 2]]],
        'mapShifts' => [['map' => 'town', 'axis' => 'x', 'at' => 3, 'by' => 2]],
      ],
      [
        'from' => 1, 'to' => 2,
        'mapRemovals' => [['map' => 'town', 'axis' => 'y', 'at' => 2, 'count' => 1, 'to' => [0, 0]]],
      ],
    ],
  ]);
  $path = '/tmp/ichiloto-map-removal-order.iedata';
  $config = makeCompatibilityConfig('town');
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(6, 3);
  $config->applySaveCompatibilityData($data);
  $legacy = serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]);

  $loaded = new SaveCompatibilityPipeline($manifest)->load($legacy, $path)->config;

  expect([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual([2, 3]);
});

it('does not reapply removed lines when a migrated save is loaded at the current version', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1,
      'mapRemovals' => [['map' => 'town', 'axis' => 'x', 'at' => 4, 'count' => 2, 'to' => [4, 3]]],
    ]],
  ]);
  $path = '/tmp/ichiloto-map-removal-once.iedata';
  $config = makeCompatibilityConfig('town');
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(5, 3);
  $config->applySaveCompatibilityData($data);
  $pipeline = new SaveCompatibilityPipeline($manifest);
  $loaded = $pipeline->load(serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]), $path);
  $current = serialize([
    'schemaVersion' => 1, 'contentVersion' => 1, 'projectId' => 'ichiloto/test-project',
    'payload' => ['slot' => $loaded->slot, 'config' => $loaded->config],
  ]);

  $reloaded = $pipeline->load($current, $path)->config;

  expect([$reloaded->playerPosition->x, $reloaded->playerPosition->y])->toEqual([4, 3]);
});

it('rejects malformed declarative map removal migrations', function (mixed $removals, string $message) {
  expect(fn() => makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [['from' => 0, 'to' => 1, 'mapRemovals' => $removals]],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, $message);
})->with([
  'empty list' => [[], 'mapRemovals must be a non-empty list'],
  'scalar list' => ['invalid', 'mapRemovals must be a non-empty list'],
  'keyed list' => [['a' => ['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [0, 0]]], 'non-empty list'],
  'not an entry' => [['x'], 'must be an array with map, axis, at, count and to'],
  'empty map' => [[['map' => ' ', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [0, 0]]], 'map must be a non-empty string'],
  'non-string map' => [[['map' => 1, 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [0, 0]]], 'map must be a non-empty string'],
  'unknown axis' => [[['map' => 'a', 'axis' => 'z', 'at' => 0, 'count' => 1, 'to' => [0, 0]]], 'axis must be "x" or "y"'],
  'negative line' => [[['map' => 'a', 'axis' => 'x', 'at' => -1, 'count' => 1, 'to' => [0, 0]]], 'at must be a non-negative integer'],
  'string line' => [[['map' => 'a', 'axis' => 'x', 'at' => '0', 'count' => 1, 'to' => [0, 0]]], 'at must be a non-negative integer'],
  'zero count' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 0, 'to' => [0, 0]]], 'count must be a positive integer'],
  'negative count' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => -1, 'to' => [0, 0]]], 'count must be a positive integer'],
  'fractional count' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1.5, 'to' => [0, 0]]], 'count must be a positive integer'],
  'missing landing' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1]], 'to must be [x, y]'],
  'negative landing' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [-1, 0]]], 'non-negative integers'],
  'string landing' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => ['0', 0]]], 'non-negative integers'],
  'keyed landing' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => ['x' => 0, 'y' => 0]]], 'to must be [x, y]'],
  'extra landing component' => [[['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [0, 0, 0]]], 'to must be [x, y]'],
]);

it('refuses to mix map removals and a project migration class', function () {
  expect(fn() => makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1, 'class' => RecordingContentVersion0To1::class,
      'mapRemovals' => [['map' => 'a', 'axis' => 'x', 'at' => 0, 'count' => 1, 'to' => [0, 0]]],
    ]],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, 'must declare either a class or declared position edits');
});

it('moves a saved player off declared relocated cells after the step map shifts', function (string $mapId, array $position, array $expected) {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 2,
    'migrations' => [
      [
        'from' => 0,
        'to' => 1,
        'mapShifts' => [['map' => 'town', 'axis' => 'y', 'at' => 4, 'by' => 2]],
        'relocations' => [
          ['map' => 'town', 'cells' => [[3, 8], [4, 8]], 'to' => [3, 9]],
        ],
      ],
      [
        'from' => 1,
        'to' => 2,
        'relocations' => [['map' => 'town', 'cells' => [[3, 9]], 'to' => [5, 9]]],
      ],
    ],
    'aliases' => ['maps' => [['from' => 'old-town', 'to' => 'town']]],
  ]);
  $path = '/tmp/ichiloto-relocation.iedata';
  $config = makeCompatibilityConfig($mapId);
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(...$position);
  $config->applySaveCompatibilityData($data);
  $legacy = serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]);

  $loaded = new SaveCompatibilityPipeline($manifest)->load($legacy, $path)->config;

  expect([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual($expected);
})->with([
  // (4, 6) shifts down to (4, 8), a relocated cell, then on through both steps.
  'shifted onto a relocated cell' => ['town', [4, 6], [5, 9]],
  'a listed cell that no shift reaches' => ['town', [3, 2], [3, 2]],
  'a later step moves an earlier landing' => ['town', [3, 7], [5, 9]],
  'an unlisted cell is untouched' => ['town', [6, 8], [6, 10]],
  'a fractional position is not a cell' => ['town', [3.5, 8], [3.5, 10]],
  'a renamed map follows its alias' => ['old-town', [4, 6], [5, 9]],
  'another map is untouched' => ['forest', [3, 8], [3, 8]],
]);

it('relocates a save once, so loading it again changes nothing', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [
      ['from' => 0, 'to' => 1, 'relocations' => [['map' => 'town', 'cells' => [[2, 2]], 'to' => [2, 3]]]],
    ],
  ]);
  $path = '/tmp/ichiloto-relocation-once.iedata';
  $config = makeCompatibilityConfig('town');
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(2, 2);
  $config->applySaveCompatibilityData($data);
  $legacy = serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]);
  $migration = $manifest->createMigrationFrom(0);

  $loaded = new SaveCompatibilityPipeline($manifest)->load($legacy, $path)->config;
  $payload = ['config' => $loaded];
  $migration?->migrate($payload);

  expect([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual([2, 3]);
});

it('rejects malformed declarative relocation migrations', function (array $entry, string $message) {
  expect(fn() => makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [array_replace(['from' => 0, 'to' => 1], $entry)],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, $message);
})->with([
  'with a class' => [
    ['class' => RecordingContentVersion0To1::class, 'relocations' => [['map' => 'a', 'cells' => [[1, 1]], 'to' => [1, 2]]]],
    'must declare either a class or declared position edits',
  ],
  'empty list' => [['relocations' => []], 'relocations must be a non-empty list'],
  'not an array' => [['relocations' => ['a']], 'must be an array with map, cells and to'],
  'empty map' => [['relocations' => [['map' => '', 'cells' => [[1, 1]], 'to' => [1, 2]]]], 'map must be a non-empty string'],
  'no cells' => [['relocations' => [['map' => 'a', 'cells' => [], 'to' => [1, 2]]]], 'cells must be a non-empty list'],
  'a malformed cell' => [['relocations' => [['map' => 'a', 'cells' => [[1]], 'to' => [1, 2]]]], 'cells[0] must be [x, y]'],
  'a negative cell' => [['relocations' => [['map' => 'a', 'cells' => [[-1, 1]], 'to' => [1, 2]]]], 'non-negative integers'],
  'a repeated cell' => [['relocations' => [['map' => 'a', 'cells' => [[1, 1], [1, 1]], 'to' => [1, 2]]]], 'cells[1] repeats [1, 1]'],
  'a string landing' => [['relocations' => [['map' => 'a', 'cells' => [[1, 1]], 'to' => ['1', 2]]]], 'to must be [x, y]'],
  'a landing on a moved cell' => [['relocations' => [['map' => 'a', 'cells' => [[1, 1]], 'to' => [1, 1]]]], 'is one of the cells it moves players off'],
]);

/** Loads a legacy save standing on a map through a manifest, returning the loaded config. */
function loadRetirementSave(SaveCompatibilityManifest $manifest, string $mapId, array $position, array $visited = []): GameConfig
{
  $path = '/tmp/ichiloto-map-retirement.iedata';
  $config = makeCompatibilityConfig($mapId);
  $data = $config->getSaveCompatibilityData();
  $data['playerPosition'] = new Vector2(...$position);
  $data['gameState'] = ['visitedMaps' => $visited];
  $config->applySaveCompatibilityData($data);

  return new SaveCompatibilityPipeline($manifest)->load(serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]), $path)->config;
}

it('moves a save on a retired map onto its destination at the first landing holding it', function (string $mapId, array $position, string $expectedMap, array $expected) {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 2,
    'migrations' => [
      [
        'from' => 0, 'to' => 1,
        'mapRetirements' => [
          [
            'map' => 'west', 'into' => 'continent',
            'landings' => [
              ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4, 'to' => [10, 2]],
              ['x' => 2, 'y' => 2, 'width' => 6, 'height' => 6, 'to' => [20, 5]],
            ],
            'to' => [30, 9],
          ],
        ],
      ],
      [
        'from' => 1, 'to' => 2,
        'mapShifts' => [['map' => 'continent', 'axis' => 'y', 'at' => 5, 'by' => 1]],
      ],
    ],
    'aliases' => ['maps' => [['from' => 'old-west', 'to' => 'west']]],
  ]);

  $loaded = loadRetirementSave($manifest, $mapId, $position);

  expect($loaded->mapId)->toBe($expectedMap)
    ->and([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual($expected);
})->with([
  'inside the first landing' => ['west', [1, 1], 'continent', [10, 2]],
  'where both landings overlap, the first wins' => ['west', [3, 3], 'continent', [10, 2]],
  'inside only the second landing, then a later shift on the destination' => ['west', [6, 6], 'continent', [20, 6]],
  'outside every landing falls back to to' => ['west', [40, 40], 'continent', [30, 10]],
  'a fractional position lands by its cell' => ['west', [3.5, 0.5], 'continent', [10, 2]],
  'a renamed retired map follows its alias' => ['old-west', [1, 1], 'continent', [10, 2]],
  'another map is untouched' => ['forest', [1, 1], 'forest', [1, 1]],
  'the destination itself keeps its saves' => ['continent', [1, 1], 'continent', [1, 1]],
]);

it('applies a step\'s shifts, removals and relocations to the retired map before retiring it', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1,
      'mapRetirements' => [['map' => 'west', 'into' => 'continent',
        'landings' => [['x' => 0, 'y' => 5, 'width' => 3, 'height' => 1, 'to' => [7, 7]]], 'to' => [1, 1]]],
      'relocations' => [['map' => 'west', 'cells' => [[2, 4]], 'to' => [2, 5]]],
      'mapShifts' => [['map' => 'west', 'axis' => 'y', 'at' => 2, 'by' => 2]],
    ]],
  ]);

  // (2, 2) shifts to (2, 4), relocates to (2, 5), then retires through the landing holding (2, 5).
  $loaded = loadRetirementSave($manifest, 'west', [2, 2]);

  expect($loaded->mapId)->toBe('continent')
    ->and([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual([7, 7]);
});

it('carries a visit to a retired map to its destination, wherever the save stands', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1,
      'mapRetirements' => [
        ['map' => 'west', 'into' => 'continent', 'to' => [1, 1]],
        ['map' => 'east', 'into' => 'continent', 'to' => [9, 1]],
      ],
    ]],
    'aliases' => ['maps' => [['from' => 'old-west', 'to' => 'west']]],
  ]);

  $inTown = loadRetirementSave($manifest, 'town', [3, 3], ['town' => true, 'old-west' => true]);
  $unvisited = loadRetirementSave($manifest, 'town', [3, 3], ['town' => true]);

  expect($inTown->mapId)->toBe('town')
    ->and([$inTown->playerPosition->x, $inTown->playerPosition->y])->toEqual([3, 3])
    ->and($inTown->gameState['visitedMaps'])->toBe(['town' => true, 'west' => true, 'continent' => true])
    ->and($unvisited->gameState['visitedMaps'])->toBe(['town' => true]);
});

it('leaves a retired map\'s one-shot events its own unless an event alias moves them', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1,
      'mapRetirements' => [['map' => 'west', 'into' => 'continent', 'to' => [1, 1]]],
    ]],
    'aliases' => ['one_shot_events' => [['from' => 'west:Q', 'to' => 'reach:Q']]],
  ]);
  $path = '/tmp/ichiloto-map-retirement-events.iedata';
  $config = makeCompatibilityConfig('west');
  $data = $config->getSaveCompatibilityData();
  $data['gameState'] = ['completedEvents' => ['west:Q' => true, 'west:A' => true]];
  $config->applySaveCompatibilityData($data);

  $loaded = new SaveCompatibilityPipeline($manifest)->load(serialize(['slot' => makeCompatibilitySlot($path), 'config' => $config]), $path)->config;

  expect($loaded->gameState['completedEvents'])->toBe(['reach:Q' => true, 'west:A' => true]);
});

it('retires a save once, so loading it again changes nothing', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [['from' => 0, 'to' => 1, 'mapRetirements' => [['map' => 'west', 'into' => 'continent', 'to' => [4, 4]]]]],
  ]);
  $path = '/tmp/ichiloto-map-retirement-once.iedata';
  $pipeline = new SaveCompatibilityPipeline($manifest);
  $loaded = loadRetirementSave($manifest, 'west', [1, 1]);
  $current = serialize([
    'schemaVersion' => 1, 'contentVersion' => 1, 'projectId' => 'ichiloto/test-project',
    'payload' => ['slot' => makeCompatibilitySlot($path), 'config' => $loaded],
  ]);

  $reloaded = $pipeline->load($current, $path)->config;

  expect($reloaded->mapId)->toBe('continent')
    ->and([$reloaded->playerPosition->x, $reloaded->playerPosition->y])->toEqual([4, 4]);
});

it('moves a place through a step as a save would, for tooling that follows a landing', function () {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1,
      'mapShifts' => [['map' => 'continent', 'axis' => 'x', 'at' => 0, 'by' => 1]],
      'mapRetirements' => [
        ['map' => 'west', 'into' => 'continent', 'to' => [4, 4]],
        ['map' => 'continent', 'into' => 'world', 'landings' => [['x' => 4, 'y' => 4, 'width' => 1, 'height' => 1, 'to' => [8, 8]]], 'to' => [0, 0]],
      ],
    ]],
  ]);
  $migration = $manifest->createMigrationFrom(0);

  // The shift on continent comes before west retires onto it, so only the later retirement moves the landing.
  expect($migration->movePlace('west', new Vector2(1, 1)))->toEqual(['mapId' => 'world', 'position' => new Vector2(8, 8)])
    ->and($migration->movePlace('forest', new Vector2(1, 1)))->toEqual(['mapId' => 'forest', 'position' => new Vector2(1, 1)]);
});

it('retires a map into itself, moving each save made on its old layout to that place on the new one', function (string $mapId, array $position, array $expected) {
  $manifest = makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [['from' => 0, 'to' => 1, 'mapRetirements' => [[
      'map' => 'world', 'into' => 'world',
      'landings' => [['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'to' => [120, 80]]],
      'to' => [150, 95],
    ]]]],
    'aliases' => ['maps' => [['from' => 'old-world', 'to' => 'world']]],
  ]);

  $loaded = loadRetirementSave($manifest, $mapId, $position);

  expect($loaded->mapId)->toBe('world')
    ->and([$loaded->playerPosition->x, $loaded->playerPosition->y])->toEqual($expected);
})->with([
  'inside a landing' => ['world', [3, 4], [120, 80]],
  'elsewhere on the old layout' => ['world', [40, 12], [150, 95]],
  'under its old alias' => ['old-world', [3, 4], [120, 80]],
]);

it('rejects malformed declarative map retirements', function (mixed $retirements, string $message, array $aliases = []) {
  expect(fn() => makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [['from' => 0, 'to' => 1, 'mapRetirements' => $retirements]],
    'aliases' => $aliases,
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, $message);
})->with([
  'empty list' => [[], 'mapRetirements must be a non-empty list'],
  'keyed list' => [['a' => ['map' => 'a', 'into' => 'b', 'to' => [0, 0]]], 'non-empty list'],
  'not an entry' => [['a'], 'must be an array with map, into, to and optional landings'],
  'empty map' => [[['map' => ' ', 'into' => 'b', 'to' => [0, 0]]], 'map must be a non-empty string'],
  'non-string map' => [[['map' => 1, 'into' => 'b', 'to' => [0, 0]]], 'map must be a non-empty string'],
  'empty destination' => [[['map' => 'a', 'into' => '', 'to' => [0, 0]]], 'into must be a non-empty string'],
  'missing fallback' => [[['map' => 'a', 'into' => 'b']], 'to must be [x, y]'],
  'negative fallback' => [[['map' => 'a', 'into' => 'b', 'to' => [0, -1]]], 'non-negative integers'],
  'string fallback' => [[['map' => 'a', 'into' => 'b', 'to' => ['0', 0]]], 'non-negative integers'],
  'empty landings' => [[['map' => 'a', 'into' => 'b', 'landings' => [], 'to' => [0, 0]]], 'landings must be a non-empty list'],
  'keyed landings' => [[['map' => 'a', 'into' => 'b', 'landings' => ['w' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'to' => [0, 0]]], 'to' => [0, 0]]],
    'landings must be a non-empty list'],
  'a landing not an array' => [[['map' => 'a', 'into' => 'b', 'landings' => [1], 'to' => [0, 0]]], 'landings[0] must be an array with x, y, width, height and to'],
  'a negative origin' => [[['map' => 'a', 'into' => 'b', 'landings' => [['x' => -1, 'y' => 0, 'width' => 1, 'height' => 1, 'to' => [0, 0]]], 'to' => [0, 0]]],
    'landings[0] x must be a non-negative integer'],
  'a string origin' => [[['map' => 'a', 'into' => 'b', 'landings' => [['x' => 0, 'y' => '0', 'width' => 1, 'height' => 1, 'to' => [0, 0]]], 'to' => [0, 0]]],
    'landings[0] y must be a non-negative integer'],
  'a zero width' => [[['map' => 'a', 'into' => 'b', 'landings' => [['x' => 0, 'y' => 0, 'width' => 0, 'height' => 1, 'to' => [0, 0]]], 'to' => [0, 0]]],
    'landings[0] width must be a positive integer'],
  'a missing height' => [[['map' => 'a', 'into' => 'b', 'landings' => [['x' => 0, 'y' => 0, 'width' => 1, 'to' => [0, 0]]], 'to' => [0, 0]]],
    'landings[0] height must be a positive integer'],
  'a malformed landing cell' => [[['map' => 'a', 'into' => 'b', 'landings' => [['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'to' => [0]]], 'to' => [0, 0]]],
    'landings[0] to must be [x, y]'],
  'a later landing malformed' => [[['map' => 'a', 'into' => 'b', 'landings' => [
    ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'to' => [0, 0]],
    ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'to' => [0, -2]],
  ], 'to' => [0, 0]]], 'landings[1] to must be [x, y]'],
]);

it('refuses to mix map retirements and a project migration class', function () {
  expect(fn() => makeCompatibilityManifest([
    'contentVersion' => 1,
    'migrations' => [[
      'from' => 0, 'to' => 1, 'class' => RecordingContentVersion0To1::class,
      'mapRetirements' => [['map' => 'a', 'into' => 'b', 'to' => [0, 0]]],
    ]],
  ]))->toThrow(InvalidSaveCompatibilityManifestException::class, 'must declare either a class or declared position edits (mapShifts, mapRemovals, relocations, mapRetirements)');
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

it('folds an aliased knowledge subject into its successor, keeping what each side earned', function () {
  $manifest = makeCompatibilityManifest(['aliases' => ['knowledge_subjects' => [['from' => 'creature.old-wolf', 'to' => 'creature.wolf']]]]);
  $config = makeCompatibilityConfig();
  $config->applySaveCompatibilityData([...$config->getSaveCompatibilityData(), 'knowledge' => ['subjects' => [
    'creature.old-wolf' => ['depth' => 2, 'observations' => ['tracks' => ['seen' => true]], 'outcomes' => ['encountered' => 3, 'defeated' => 2], 'reports' => []],
    'creature.rat' => ['depth' => 1, 'observations' => [], 'outcomes' => ['encountered' => 1], 'reports' => []],
    'creature.wolf' => ['depth' => 1, 'observations' => ['howl' => ['seen' => true]], 'outcomes' => ['encountered' => 2, 'escaped' => 1], 'reports' => []],
  ]]]);

  $subjects = new SaveContentResolver($manifest, '/saves/knowledge.iedata')->resolve($config)->getSaveCompatibilityData()['knowledge']['subjects'];

  expect(array_keys($subjects))->toBe(['creature.rat', 'creature.wolf'])
    ->and($subjects['creature.wolf']['depth'])->toBe(2)
    ->and($subjects['creature.wolf']['outcomes'])->toBe(['defeated' => 2, 'encountered' => 5, 'escaped' => 1])
    ->and(array_keys($subjects['creature.wolf']['observations']))->toEqualCanonicalizing(['howl', 'tracks'])
    ->and($subjects['creature.rat']['outcomes'])->toBe(['encountered' => 1]);
});

it('refuses a save holding a tombstoned knowledge subject', function () {
  $manifest = makeCompatibilityManifest(['tombstones' => ['knowledge_subjects' => ['creature.removed']]]);
  $config = makeCompatibilityConfig();
  $config->applySaveCompatibilityData([...$config->getSaveCompatibilityData(), 'knowledge' => ['subjects' => [
    'creature.removed' => ['depth' => 1, 'observations' => [], 'outcomes' => [], 'reports' => []],
  ]]]);

  expect(fn() => new SaveContentResolver($manifest, '/saves/knowledge.iedata')->resolve($config))
    ->toThrow(RuntimeException::class, 'creature.removed');
});
