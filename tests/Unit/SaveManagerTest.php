<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;

class SaveManagerTestGame extends Game
{
  public function __construct()
  {
  }

  public function __destruct()
  {
  }
}

class SaveManagerBrokenPayloadStub
{
  public function __serialize(): array
  {
    return ['items' => []];
  }

  public function __unserialize(array $data): void
  {
    throw new \UnexpectedValueException('Invalid serialized collection payload.');
  }
}

function makeSaveManagerTestGame(): Game
{
  return new SaveManagerTestGame();
}

function makeSaveManagerTestConfig(): GameConfig
{
  $party = new Party();
  $party->location = new PartyLocation('Happyville Town Square', 'Happyville');

  return new GameConfig(
    mapId: 'happyville/town-square',
    party: $party,
    playerPosition: new Vector2(12, 8),
    playerShape: new Rect(0, 0, 1, 1),
    playerHeading: MovementHeading::SOUTH,
    playerStats: [],
    events: [],
    playerSprite: ['v'],
    playerSprites: [
      'north' => ['^'],
      'east' => ['>'],
      'south' => ['v'],
      'west' => ['<'],
    ],
    playTimeSeconds: 229,
  );
}

function cleanupSaveManagerTestFiles(string $slotPath): void
{
  $saveDirectory = dirname($slotPath);
  $quickSaveDirectory = $saveDirectory . '/quick';

  if (file_exists($slotPath)) {
    unlink($slotPath);
  }

  if (is_dir($quickSaveDirectory)) {
    rmdir($quickSaveDirectory);
  }

  if (is_dir($saveDirectory)) {
    rmdir($saveDirectory);
  }
}

it('loads binary .iedata save files and resolves slot summaries', function () {
  $slug = 'save-manager-' . uniqid();
  $manager = new SaveManager(
    makeSaveManagerTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick"
  );
  $slotPath = $manager->getSlotPath(2);
  $slot = new SaveSlot(
    slot: 2,
    path: $slotPath,
    isEmpty: false,
    locationName: 'Happyville Town Square',
    leaderName: 'Ralph',
    leaderLevel: 1,
    playTimeSeconds: 229,
    savedAt: 1234567890,
  );
  $payload = serialize([
    'slot' => $slot,
    'config' => makeSaveManagerTestConfig(),
  ]);

  file_put_contents($slotPath, 'IED1' . gzencode($payload, 9));

  $loadedSave = $manager->loadSlot(2);
  $slots = $manager->getSaveSlots(3);

  expect($loadedSave->slot->slot)->toBe(2)
    ->and($loadedSave->slot->locationName)->toBe('Happyville Town Square')
    ->and($loadedSave->config->mapId)->toBe('happyville/town-square')
    ->and($slots[0]->isEmpty)->toBeTrue()
    ->and($slots[1]->isEmpty)->toBeFalse()
    ->and($slots[1]->getLeaderSummary())->toBe('Ralph Lv 1')
    ->and($slots[2]->isEmpty)->toBeTrue();

  cleanupSaveManagerTestFiles($slotPath);
});

it('marks incompatible save slots instead of crashing the slot list', function () {
  $slug = 'save-manager-invalid-' . uniqid();
  $manager = new SaveManager(
    makeSaveManagerTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick"
  );
  $slotPath = $manager->getSlotPath(1);
  $slot = new SaveSlot(
    slot: 1,
    path: $slotPath,
    isEmpty: false,
    locationName: 'Broken Save',
    leaderName: 'Ralph',
    leaderLevel: 1,
    playTimeSeconds: 12,
    savedAt: 1234567890,
  );
  $invalidPayload = serialize([
    'slot' => $slot,
    'config' => 'invalid-config',
  ]);

  file_put_contents($slotPath, 'IED1' . gzencode($invalidPayload, 9));

  $slots = $manager->getSaveSlots(1);

  expect($slots)->toHaveCount(1)
    ->and($slots[0]->isEmpty)->toBeFalse()
    ->and($slots[0]->isLoadable)->toBeFalse()
    ->and($slots[0]->locationName)->toBe('Incompatible Save');

  cleanupSaveManagerTestFiles($slotPath);
});

it('marks slots as incompatible when nested payload unserialization fails', function () {
  $slug = 'save-manager-nested-invalid-' . uniqid();
  $manager = new SaveManager(
    makeSaveManagerTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick"
  );
  $slotPath = $manager->getSlotPath(1);
  $slot = new SaveSlot(
    slot: 1,
    path: $slotPath,
    isEmpty: false,
    locationName: 'Broken Save',
    leaderName: 'Ralph',
    leaderLevel: 1,
    playTimeSeconds: 12,
    savedAt: 1234567890,
  );
  $brokenPayload = serialize([
    'slot' => $slot,
    'config' => new SaveManagerBrokenPayloadStub(),
  ]);

  file_put_contents($slotPath, 'IED1' . gzencode($brokenPayload, 9));

  $slots = $manager->getSaveSlots(1);

  expect($slots)->toHaveCount(1)
    ->and($slots[0]->isEmpty)->toBeFalse()
    ->and($slots[0]->isLoadable)->toBeFalse()
    ->and($slots[0]->statusMessage)->toBe('This save file is from an incompatible format.');

  cleanupSaveManagerTestFiles($slotPath);
});

it('returns the latest loadable save file when newer incompatible saves exist', function () {
  $slug = 'save-manager-latest-loadable-' . uniqid();
  $manager = new SaveManager(
    makeSaveManagerTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick"
  );
  $olderPath = $manager->getSlotPath(1);
  $newerPath = $manager->getSlotPath(2);
  $validSlot = new SaveSlot(
    slot: 1,
    path: $olderPath,
    isEmpty: false,
    locationName: 'Happyville Town Square',
    leaderName: 'Ralph',
    leaderLevel: 1,
    playTimeSeconds: 229,
    savedAt: 1234567890,
  );
  $validPayload = serialize([
    'slot' => $validSlot,
    'config' => makeSaveManagerTestConfig(),
  ]);
  $invalidPayload = serialize([
    'slot' => new SaveSlot(
      slot: 2,
      path: $newerPath,
      isEmpty: false,
      locationName: 'Broken Save',
      leaderName: 'Ralph',
      leaderLevel: 1,
      playTimeSeconds: 12,
      savedAt: 1234567891,
    ),
    'config' => 'invalid-config',
  ]);

  file_put_contents($olderPath, 'IED1' . gzencode($validPayload, 9));
  file_put_contents($newerPath, 'IED1' . gzencode($invalidPayload, 9));
  touch($olderPath, 100);
  touch($newerPath, 200);

  expect($manager->getLatestSaveFile())->toBe($newerPath)
    ->and($manager->getLatestLoadableSaveFile())->toBe($olderPath);

  if (file_exists($newerPath)) {
    unlink($newerPath);
  }

  cleanupSaveManagerTestFiles($olderPath);
});
